<?php
declare(strict_types=1);

/*
 * Profile pictures. Every signed-in person can set their own on Settings > Account.
 * JPEG, PNG, WebP and GIF (animated GIFs stay animated: the file is stored as it was sent, never re-encoded).
 *
 * The upload is checked on the server, not trusted: it must be a real upload, within the size and pixel limits, and its
 * contents must decode as one of the four image types. The type found in the contents (never the file name the browser
 * sent) decides the extension, and the stored name is random, so nothing a visitor chooses reaches the file system.
 * assets/avatars/.htaccess also refuses to run or serve anything there that is not a picture.
 */

const AVATAR_MAX_BYTES = 2 * 1024 * 1024;   // must stay below LimitRequestBody in settings/.htaccess (4 MB) and upload_max_filesize in docker/php.ini (3 MB)
const AVATAR_MAX_PIXELS = 4096;             // width and height
const AVATAR_DIR = 'assets/avatars/';
const AVATAR_TYPES = [IMAGETYPE_GIF => 'gif', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

/** Only pictures this module wrote may be deleted by it; the shared starter picture (oia-cat.gif) never is. */
function avatar_is_own_file(?string $path): bool
{
    return $path !== null && preg_match('#^' . preg_quote(AVATAR_DIR, '#') . 'u\d+-[a-f0-9]{12}\.(gif|jpg|png|webp)$#', $path) === 1;
}

/** Checks one entry of $_FILES and, when it is fine, makes it the person's picture. Returns [success, message for the flash]. */
function avatar_save(PDO $pdo, array $admin, ?array $file): array
{
    if (!$file || !isset($file['error'])) { return [false, 'Choose a picture first.']; }
    $err = (int) $file['error'];
    if ($err === UPLOAD_ERR_NO_FILE) { return [false, 'Choose a picture first.']; }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { return [false, 'That file is too large. The limit is ' . (AVATAR_MAX_BYTES / 1048576) . ' MB.']; }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) { return [false, 'The upload did not work. Try again.']; }
    $size = (int) $file['size'];
    if ($size < 1 || $size > AVATAR_MAX_BYTES) { return [false, 'That file is too large. The limit is ' . (AVATAR_MAX_BYTES / 1048576) . ' MB.']; }

    $bytes = (string) file_get_contents((string) $file['tmp_name']);
    $info = $bytes !== '' ? @getimagesizefromstring($bytes) : false;
    if ($info === false || !isset(AVATAR_TYPES[$info[2]])) { return [false, 'Use a JPG, PNG, WebP or GIF picture (animated GIFs work too).']; }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] > AVATAR_MAX_PIXELS || $info[1] > AVATAR_MAX_PIXELS) {
        return [false, 'The picture may be at most ' . AVATAR_MAX_PIXELS . ' by ' . AVATAR_MAX_PIXELS . ' pixels.'];
    }
    if (stripos($bytes, '<?php') !== false || str_contains($bytes, '<?=')) { return [false, 'That file contains code and cannot be used as a picture.']; }

    $name = 'u' . (int) $admin['id'] . '-' . bin2hex(random_bytes(6)) . '.' . AVATAR_TYPES[$info[2]];
    $dir = dirname(__DIR__, 2) . '/' . AVATAR_DIR;
    if (!is_dir($dir) || !is_writable($dir) || @file_put_contents($dir . $name, $bytes) !== strlen($bytes)) {
        try { log_activity('warning', 'Profile picture could not be saved', 'assets/avatars is not writable', (string) $admin['username']); } catch (Throwable $e) { /* ignore */ }
        return [false, 'The picture could not be saved on the server. Ask an administrator to check the assets/avatars folder.'];
    }
    @chmod($dir . $name, 0644);

    $old = $pdo->prepare('SELECT avatar FROM admins WHERE id = ?');
    $old->execute([$admin['id']]);
    $old = $old->fetchColumn();
    $pdo->prepare('UPDATE admins SET avatar = ? WHERE id = ?')->execute([AVATAR_DIR . $name, $admin['id']]);
    avatar_delete_file(is_string($old) ? $old : null);
    log_activity('info', 'Profile picture changed', AVATAR_TYPES[$info[2]] === 'gif' ? 'gif' : AVATAR_TYPES[$info[2]], (string) $admin['username']);
    return [true, 'Your profile picture is updated.'];
}

/** Goes back to the initials. */
function avatar_remove(PDO $pdo, array $admin): void
{
    $old = $pdo->prepare('SELECT avatar FROM admins WHERE id = ?');
    $old->execute([$admin['id']]);
    $old = $old->fetchColumn();
    $pdo->prepare('UPDATE admins SET avatar = NULL WHERE id = ?')->execute([$admin['id']]);
    avatar_delete_file(is_string($old) ? $old : null);
    log_activity('info', 'Profile picture removed', '', (string) $admin['username']);
}

function avatar_delete_file(?string $path): void
{
    if (avatar_is_own_file($path)) { @unlink(dirname(__DIR__, 2) . '/' . $path); }
}
