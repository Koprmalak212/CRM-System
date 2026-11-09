<?php
declare(strict_types=1);

/**
 * Language toggle backed by Google Website Translator.
 * The page is authored in English. Picking a language sets Google's "googtrans"
 * cookie and reloads; the Google script then translates the whole page, and the
 * cookie keeps every other page translated too.
 */
function languages(): array
{
    return [
        'en' => 'English', 'af' => 'Afrikaans', 'sq' => 'Shqip', 'am' => 'አማርኛ', 'ar' => 'العربية', 'hy' => 'Հայերեն',
        'az' => 'Azərbaycanca', 'eu' => 'Euskara', 'be' => 'Беларуская', 'bn' => 'বাংলা', 'bs' => 'Bosanski', 'bg' => 'Български',
        'ca' => 'Català', 'ceb' => 'Cebuano', 'zh-CN' => '中文 (简体)', 'zh-TW' => '中文 (繁體)', 'co' => 'Corsu', 'hr' => 'Hrvatski',
        'cs' => 'Čeština', 'da' => 'Dansk', 'nl' => 'Nederlands', 'eo' => 'Esperanto', 'et' => 'Eesti', 'fi' => 'Suomi',
        'fr' => 'Français', 'fy' => 'Frysk', 'gl' => 'Galego', 'ka' => 'ქართული', 'de' => 'Deutsch', 'el' => 'Ελληνικά',
        'gu' => 'ગુજરાતી', 'ht' => 'Kreyòl ayisyen', 'ha' => 'Hausa', 'haw' => 'ʻŌlelo Hawaiʻi', 'iw' => 'עברית', 'hi' => 'हिन्दी',
        'hmn' => 'Hmoob', 'hu' => 'Magyar', 'is' => 'Íslenska', 'ig' => 'Igbo', 'id' => 'Bahasa Indonesia', 'ga' => 'Gaeilge',
        'it' => 'Italiano', 'ja' => '日本語', 'jw' => 'Basa Jawa', 'kn' => 'ಕನ್ನಡ', 'kk' => 'Қазақша', 'km' => 'ខ្មែរ',
        'rw' => 'Kinyarwanda', 'ko' => '한국어', 'ku' => 'Kurdî', 'ky' => 'Кыргызча', 'lo' => 'ລາວ', 'la' => 'Latina',
        'lv' => 'Latviešu', 'lt' => 'Lietuvių', 'lb' => 'Lëtzebuergesch', 'mk' => 'Македонски', 'mg' => 'Malagasy', 'ms' => 'Bahasa Melayu',
        'ml' => 'മലയാളം', 'mt' => 'Malti', 'mi' => 'Māori', 'mr' => 'मराठी', 'mn' => 'Монгол', 'my' => 'မြန်မာ',
        'ne' => 'नेपाली', 'no' => 'Norsk', 'ny' => 'Chichewa', 'or' => 'ଓଡ଼ିଆ', 'ps' => 'پښتو', 'fa' => 'فارسی',
        'pl' => 'Polski', 'pt' => 'Português', 'pa' => 'ਪੰਜਾਬੀ', 'ro' => 'Română', 'ru' => 'Русский', 'sm' => 'Gagana Samoa',
        'gd' => 'Gàidhlig', 'sr' => 'Српски', 'st' => 'Sesotho', 'sn' => 'Shona', 'sd' => 'سنڌي', 'si' => 'සිංහල',
        'sk' => 'Slovenčina', 'sl' => 'Slovenščina', 'so' => 'Soomaali', 'es' => 'Español', 'su' => 'Basa Sunda', 'sw' => 'Kiswahili',
        'sv' => 'Svenska', 'tl' => 'Filipino', 'tg' => 'Тоҷикӣ', 'ta' => 'தமிழ்', 'tt' => 'Татарча', 'te' => 'తెలుగు',
        'th' => 'ไทย', 'tr' => 'Türkçe', 'tk' => 'Türkmençe', 'uk' => 'Українська', 'ur' => 'اردو', 'ug' => 'ئۇيغۇرچە',
        'uz' => 'Oʻzbekcha', 'vi' => 'Tiếng Việt', 'cy' => 'Cymraeg', 'xh' => 'isiXhosa', 'yi' => 'ייִדיש', 'yo' => 'Yorùbá', 'zu' => 'isiZulu',
    ];
}

function lang_widget(string $wrapClass = ''): string
{
    $langs = languages();
    $codes = implode(',', array_keys($langs));
    $opts = '';
    foreach ($langs as $code => $name) {
        $opts .= '<option value="' . e($code) . '">' . e($name) . '</option>';
    }
    ob_start();
    ?>
<div class="<?= e($wrapClass) ?> notranslate" translate="no">
  <label for="langSelect" class="sr-only">Language</label>
  <div class="relative">
    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-soft">
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
    </span>
    <select id="langSelect" class="h-10 w-40 appearance-none rounded-xl border border-line bg-panel pl-9 pr-8 text-sm font-medium focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime sm:w-48"><?= $opts ?></select>
    <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-soft"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
    <div id="trConsent" hidden role="dialog" aria-modal="false" aria-labelledby="trConsentTitle" aria-describedby="trConsentText"
         class="absolute right-0 top-12 z-50 w-[22rem] max-w-[calc(100vw-2rem)] rounded-2xl border border-line bg-panel p-5 text-left shadow-[0_18px_40px_-12px_rgba(0,0,0,.8)]">
      <h2 id="trConsentTitle" class="text-base font-bold">Allow Google to translate this page?</h2>
      <p id="trConsentText" class="mt-2 text-sm leading-relaxed text-soft">Translation is done by Google. To translate, the text of each page is sent to Google, including any names, email addresses and IP addresses shown on it. Google may process it outside the EU. Nothing is sent until you allow it, and you can withdraw at any time from the footer.</p>
      <div class="mt-4 flex gap-2">
        <button type="button" id="trAllow" class="h-10 flex-1 rounded-xl bg-lime px-4 text-sm font-bold text-navy hover:brightness-95">Allow translation</button>
        <button type="button" id="trCancel" class="h-10 rounded-xl border border-line px-4 text-sm font-semibold hover:bg-white/5">Cancel</button>
      </div>
    </div>
  </div>
</div>
<div id="gt_el" class="hidden" aria-hidden="true"></div>
<style nonce="<?= csp_nonce() ?>">
  /* Hide Google's own banner and tooltip; our selector replaces them */
  body > .skiptranslate, .goog-te-banner-frame, #goog-gt-tt, .goog-te-balloon-frame, .goog-tooltip { display: none !important; }
  body { top: 0 !important; }
  .goog-text-highlight { background: none !important; box-shadow: none !important; }
  font { vertical-align: baseline !important; }
</style>
<script nonce="<?= csp_nonce() ?>">
(function () {
  var sel = document.getElementById('langSelect');
  function readLang() {
    var m = document.cookie.match(/(?:^|;\s*)googtrans=\/[^/]*\/([^;]+)/);
    return m ? decodeURIComponent(m[1]) : 'en';
  }
  function writeCookie(value, expire) {
    var base = 'googtrans=' + value + ';path=/' + (expire ? ';expires=Thu, 01 Jan 1970 00:00:00 GMT' : '');
    document.cookie = base;
    var host = location.hostname;
    if (host.indexOf('.') > -1) document.cookie = base + ';domain=' + host;
  }
  // Consent record (GDPR art. 7): stored with a timestamp, checked before anything is sent to Google.
  var CONSENT_KEY = 'translateConsent';
  function hasConsent() { try { return !!localStorage.getItem(CONSENT_KEY); } catch (e) { return false; } }
  function giveConsent() { try { localStorage.setItem(CONSENT_KEY, JSON.stringify({ at: new Date().toISOString(), version: 1, scope: 'google-translate' })); } catch (e) { /* storage blocked: consent can not be remembered */ } }
  function withdrawConsent() { try { localStorage.removeItem(CONSENT_KEY); } catch (e) { /* ignore */ } writeCookie('', true); location.reload(); }
  var current = readLang();
  if (current !== 'en' && !hasConsent()) { writeCookie('', true); current = 'en'; }   // never load Google without consent
  var panel = document.getElementById('trConsent'), pending = null;
  function closePanel() { if (panel) panel.hidden = true; if (sel) sel.value = current; pending = null; }
  function apply(code) { writeCookie('/en/' + code, false); location.reload(); }
  if (sel) {
    sel.value = current;
    if (sel.value !== current) sel.value = 'en';
    sel.addEventListener('change', function () {
      if (sel.value === 'en') { writeCookie('', true); location.reload(); return; }
      if (hasConsent()) { apply(sel.value); return; }
      pending = sel.value; panel.hidden = false; document.getElementById('trAllow').focus();
    });
    document.getElementById('trAllow').addEventListener('click', function () { if (pending) { giveConsent(); apply(pending); } });
    document.getElementById('trCancel').addEventListener('click', closePanel);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && panel && !panel.hidden) { closePanel(); sel.focus(); } });
  }
  document.addEventListener('DOMContentLoaded', function () {
    var w = document.getElementById('trWithdraw');
    if (w && hasConsent()) { w.hidden = false; w.addEventListener('click', withdrawConsent); }
  });
  if (current !== 'en') {
    window.googleTranslateElementInit = function () {
      new google.translate.TranslateElement({ pageLanguage: 'en', includedLanguages: '<?= e($codes) ?>', autoDisplay: false }, 'gt_el');
    };
    var s = document.createElement('script');
    s.src = 'https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
    s.async = true;
    document.head.appendChild(s);
  }
})();
</script>
<?php
    return (string) ob_get_clean();
}
