<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/theme.php';
$home = !empty($_SESSION['admin']) ? 'index.php' : 'login.php';
$homeLabel = !empty($_SESSION['admin']) ? 'Terug naar het dashboard' : 'Terug naar inloggen';
// Source: https://telecomlijn.nl/privacybeleid/ (copied with permission of Telecomlijn). Last updated by Telecomlijn: december 2025.
?>
<!doctype html>
<html lang="nl" class="h-full scroll-smooth">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Privacybeleid · Telecomlijn</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%230BF002'/%3E%3C/svg%3E">
<link rel="stylesheet" href="assets/fonts/fonts.css">
<script src="assets/vendor/tailwind-3.4.17.js"></script>
<?= theme_head() ?>
<style nonce="<?= csp_nonce() ?>">
  main .grid > * { min-width: 0; }
  .overflow-x-auto { position: relative; }
</style>
<script nonce="<?= csp_nonce() ?>">document.documentElement.classList.add('js');setTimeout(function(){if(!window.__motion)document.documentElement.classList.add('reveal-all')},3000);</script>
<link rel="stylesheet" href="assets/motion.css">
</head>
<body class="min-h-full bg-ground font-sans text-ink antialiased">
<header class="border-b border-line bg-navy">
  <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-8">
    <a href="<?= $home ?>" aria-label="Telecomlijn"><img src="assets/telecomlijn-logo-white.png" alt="Telecomlijn" width="520" height="151" class="logo-on-dark h-9 w-auto"><img src="assets/telecomlijn-logo-color.png" alt="" aria-hidden="true" width="274" height="79" class="logo-on-light h-9 w-auto"></a>
    <a href="<?= $home ?>" class="pressable inline-flex h-10 items-center gap-1.5 rounded-xl border border-white/15 px-4 text-sm font-semibold text-white hover:bg-white/10"><?= e($homeLabel) ?></a>
  </div>
</header>

<main class="mx-auto max-w-5xl px-4 py-10 sm:px-8 sm:py-14">
  <div class="rise mb-10 max-w-3xl">
    <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">Privacybeleid</h1>
    <p class="mt-4 text-lg leading-relaxed text-soft">Telecomlijn Zakelijk hecht grote waarde aan de bescherming van uw persoonsgegevens. In dit privacybeleid informeren wij u over hoe wij omgaan met uw gegevens.</p>
    <p class="mt-3 text-sm text-soft">Laatst bijgewerkt: december 2025</p>
  </div>

  <div class="grid items-start gap-8 lg:grid-cols-[240px_1fr]">
    <nav class="hidden lg:sticky lg:top-6 lg:block" aria-label="Inhoud"><p class="mb-2 px-3 text-xs font-semibold text-soft">Inhoud</p><ul class="space-y-0.5 text-sm"><li><a href="#wie-zijn-wij" class="block rounded-lg px-3 py-1.5 text-soft hover:bg-white/5 hover:text-ink">1. Wie zijn wij?</a></li><li><a href="#welke-persoonsgegevens-verwerken-wij" class="block rounded-lg px-3 py-1.5 text-soft hover:bg-white/5 hover:text-ink">2. Welke persoonsgegevens verwerken wij?</a></li><li><a href="#waarvoor-gebruiken-wij-uw-gegevens" class="block rounded-lg px-3 py-1.5 text-soft hover:bg-white/5 hover:text-ink">3. Waarvoor gebruiken wij uw gegevens?</a></li><li><a href="#hoe-lang-bewaren-wij-uw-gegevens" class="block rounded-lg px-3 py-1.5 text-soft hover:bg-white/5 hover:text-ink">4. Hoe lang bewaren wij uw gegevens?</a></li><li><a href="#met-wie-delen-wij-uw-gegevens" class="block rounded-lg px-3 py-1.5 text-soft hover:bg-white/5 hover:text-ink">5. Met wie delen wij uw gegevens?</a></li><li><a href="#hoe-beveiligen-wij-uw-gegevens" class="block rounded-lg px-3 py-1.5 text-soft hover:bg-white/5 hover:text-ink">6. Hoe beveiligen wij uw gegevens?</a></li><li><a href="#cookies-en-tracking" class="block rounded-lg px-3 py-1.5 text-soft hover:bg-white/5 hover:text-ink">7. Cookies en tracking</a></li><li><a href="#uw-rechten" class="block rounded-lg px-3 py-1.5 text-soft hover:bg-white/5 hover:text-ink">8. Uw rechten</a></li><li><a href="#klachten" class="block rounded-lg px-3 py-1.5 text-soft hover:bg-white/5 hover:text-ink">9. Klachten</a></li><li><a href="#contact" class="block rounded-lg px-3 py-1.5 text-soft hover:bg-white/5 hover:text-ink">10. Contact</a></li><li><a href="#wijzigingen" class="block rounded-lg px-3 py-1.5 text-soft hover:bg-white/5 hover:text-ink">11. Wijzigingen</a></li></ul></nav>
    <div class="space-y-6">
<section id="wie-zijn-wij" class="rise scroll-mt-24 rounded-2xl border border-line bg-panel p-6 shadow-panel sm:p-8" aria-labelledby="wie-zijn-wij-h">
  <h2 id="wie-zijn-wij-h" class="text-2xl font-bold tracking-tight">1. Wie zijn wij?</h2>
  <p class="mt-4 text-[15px] leading-relaxed text-soft">Dit is het privacybeleid van Telecomlijn Zakelijk, een onderneming die zich richt op de zakelijke markt met VoIP-telefonie, mobiele telefonie en internetdiensten.</p>
  <dl class="mt-5 grid gap-x-8 gap-y-4 text-[15px] sm:grid-cols-2">
    <div><dt class="text-soft">Bedrijfsnaam</dt><dd class="mt-0.5"><span class="font-semibold">Telecomlijn Zakelijk</span></dd></div>
    <div><dt class="text-soft">KvK-nummer</dt><dd class="mt-0.5"><span class="font-semibold">65586492</span></dd></div>
    <div><dt class="text-soft">Adres</dt><dd class="mt-0.5"><span class="font-semibold">Seinhuiswachter 12, 3034 KH Rotterdam</span></dd></div>
    <div><dt class="text-soft">E-mail</dt><dd class="mt-0.5"><a class="font-semibold underline decoration-lime decoration-2 underline-offset-4" href="mailto:office@telecomlijn.nl">office@telecomlijn.nl</a></dd></div>
  </dl>
</section>
<section id="welke-persoonsgegevens-verwerken-wij" class="rise scroll-mt-24 rounded-2xl border border-line bg-panel p-6 shadow-panel sm:p-8" aria-labelledby="welke-persoonsgegevens-verwerken-wij-h">
  <h2 id="welke-persoonsgegevens-verwerken-wij-h" class="text-2xl font-bold tracking-tight">2. Welke persoonsgegevens verwerken wij?</h2>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Afhankelijk van de diensten die u bij ons afneemt, verwerken wij de volgende categorieën persoonsgegevens:</p>
  <h3 class="mt-6 text-base font-bold">Contactgegevens</h3>
  <ul class="mt-3 list-disc space-y-1.5 pl-5 text-[15px] leading-relaxed marker:text-lime">
    <li>Naam en voorletters</li>
    <li>Adresgegevens (straat, huisnummer, postcode, woonplaats)</li>
    <li>E-mailadres</li>
    <li>Telefoonnummer(s)</li>
    <li>Bedrijfsnaam en functie</li>
  </ul>
  <h3 class="mt-6 text-base font-bold">Financiële gegevens</h3>
  <ul class="mt-3 list-disc space-y-1.5 pl-5 text-[15px] leading-relaxed marker:text-lime">
    <li>IBAN/bankrekeningnummer</li>
    <li>BTW-nummer</li>
    <li>Factuurgegevens en betalingshistorie</li>
  </ul>
  <h3 class="mt-6 text-base font-bold">Technische gegevens</h3>
  <ul class="mt-3 list-disc space-y-1.5 pl-5 text-[15px] leading-relaxed marker:text-lime">
    <li>IP-adres</li>
    <li>Browsertype en -versie</li>
    <li>Apparaatinformatie</li>
    <li>Gespreksgegevens (CDR&#x27;s - Call Detail Records)</li>
    <li>Gebruiksgegevens van onze diensten</li>
  </ul>
  <h3 class="mt-6 text-base font-bold">Communicatiegegevens</h3>
  <ul class="mt-3 list-disc space-y-1.5 pl-5 text-[15px] leading-relaxed marker:text-lime">
    <li>Correspondentie via e-mail of telefoon</li>
    <li>Supporttickets en gespreksnotities</li>
    <li>Gespreksopnames (indien van toepassing en met toestemming)</li>
  </ul>
</section>
<section id="waarvoor-gebruiken-wij-uw-gegevens" class="rise scroll-mt-24 rounded-2xl border border-line bg-panel p-6 shadow-panel sm:p-8" aria-labelledby="waarvoor-gebruiken-wij-uw-gegevens-h">
  <h2 id="waarvoor-gebruiken-wij-uw-gegevens-h" class="text-2xl font-bold tracking-tight">3. Waarvoor gebruiken wij uw gegevens?</h2>
  <h3 class="mt-6 text-base font-bold">Uitvoering van de overeenkomst</h3>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Wij verwerken uw gegevens om onze diensten aan u te kunnen leveren, zoals:</p>
  <ul class="mt-3 list-disc space-y-1.5 pl-5 text-[15px] leading-relaxed marker:text-lime">
    <li>Het aanleggen en beheren van uw telefonie- of internetverbinding</li>
    <li>Het factureren van onze diensten</li>
    <li>Het bieden van technische ondersteuning</li>
    <li>Het uitvoeren van nummerporteringen</li>
  </ul>
  <p class="mt-3 text-[15px] leading-relaxed text-soft"><strong class="font-semibold text-ink">Grondslag:</strong> uitvoering overeenkomst (Art. 6 lid 1 sub b AVG)</p>
  <h3 class="mt-6 text-base font-bold">Wettelijke verplichtingen</h3>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Wij zijn wettelijk verplicht bepaalde gegevens te verwerken voor:</p>
  <ul class="mt-3 list-disc space-y-1.5 pl-5 text-[15px] leading-relaxed marker:text-lime">
    <li>Fiscale administratie en belastingaangiften (7 jaar bewaarplicht)</li>
    <li>Telecommunicatiewetgeving (o.a. noodoproepen, nummerportabiliteit)</li>
    <li>Medewerking aan rechtmatige verzoeken van autoriteiten</li>
  </ul>
  <p class="mt-3 text-[15px] leading-relaxed text-soft"><strong class="font-semibold text-ink">Grondslag:</strong> wettelijke verplichting (Art. 6 lid 1 sub c AVG)</p>
  <h3 class="mt-6 text-base font-bold">Gerechtvaardigd belang</h3>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Voor de volgende doeleinden hebben wij een gerechtvaardigd belang:</p>
  <ul class="mt-3 list-disc space-y-1.5 pl-5 text-[15px] leading-relaxed marker:text-lime">
    <li>Verbetering van onze dienstverlening en website</li>
    <li>Fraudepreventie en netwerkbeveiliging</li>
    <li>Interne analyses en rapportages</li>
    <li>Direct marketing aan bestaande klanten (u kunt zich hiervoor afmelden)</li>
  </ul>
  <p class="mt-3 text-[15px] leading-relaxed text-soft"><strong class="font-semibold text-ink">Grondslag:</strong> gerechtvaardigd belang (Art. 6 lid 1 sub f AVG)</p>
  <h3 class="mt-6 text-base font-bold">Toestemming</h3>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Met uw uitdrukkelijke toestemming verwerken wij gegevens voor:</p>
  <ul class="mt-3 list-disc space-y-1.5 pl-5 text-[15px] leading-relaxed marker:text-lime">
    <li>Het plaatsen van niet-essentiële cookies</li>
    <li>Het verzenden van nieuwsbrieven en promotionele berichten</li>
    <li>Gespreksopnames voor trainingsdoeleinden</li>
  </ul>
  <p class="mt-3 text-[15px] leading-relaxed text-soft"><strong class="font-semibold text-ink">Grondslag:</strong> toestemming (Art. 6 lid 1 sub a AVG)</p>
</section>
<section id="hoe-lang-bewaren-wij-uw-gegevens" class="rise scroll-mt-24 rounded-2xl border border-line bg-panel p-6 shadow-panel sm:p-8" aria-labelledby="hoe-lang-bewaren-wij-uw-gegevens-h">
  <h2 id="hoe-lang-bewaren-wij-uw-gegevens-h" class="text-2xl font-bold tracking-tight">4. Hoe lang bewaren wij uw gegevens?</h2>
  <p class="mt-4 text-[15px] leading-relaxed text-soft">Wij bewaren uw persoonsgegevens niet langer dan noodzakelijk voor het doel waarvoor ze zijn verzameld, tenzij wij wettelijk verplicht zijn gegevens langer te bewaren.</p>
  <div class="mt-5 overflow-x-auto rounded-xl border border-line"><table class="w-full min-w-[420px] text-left text-sm">
    <thead class="bg-butter-50 text-xs text-soft"><tr><th scope="col" class="px-4 py-3 font-semibold">Type gegevens</th><th scope="col" class="px-4 py-3 font-semibold">Bewaartermijn</th></tr></thead><tbody class="divide-y divide-line">
      <tr><td class="px-4 py-3 font-semibold">Klantgegevens (actieve klant)</td><td class="px-4 py-3">Gedurende de looptijd van de overeenkomst</td></tr>
      <tr><td class="px-4 py-3 font-semibold">Klantgegevens (na beëindiging)</td><td class="px-4 py-3">2 jaar na einde overeenkomst</td></tr>
      <tr><td class="px-4 py-3 font-semibold">Financiële administratie</td><td class="px-4 py-3">7 jaar (wettelijke bewaarplicht)</td></tr>
      <tr><td class="px-4 py-3 font-semibold">Gespreksgegevens (CDR&#x27;s)</td><td class="px-4 py-3">12 maanden</td></tr>
      <tr><td class="px-4 py-3 font-semibold">Supporttickets</td><td class="px-4 py-3">3 jaar na afhandeling</td></tr>
      <tr><td class="px-4 py-3 font-semibold">Websitestatistieken</td><td class="px-4 py-3">26 maanden</td></tr>
    </tbody></table></div>
</section>
<section id="met-wie-delen-wij-uw-gegevens" class="rise scroll-mt-24 rounded-2xl border border-line bg-panel p-6 shadow-panel sm:p-8" aria-labelledby="met-wie-delen-wij-uw-gegevens-h">
  <h2 id="met-wie-delen-wij-uw-gegevens-h" class="text-2xl font-bold tracking-tight">5. Met wie delen wij uw gegevens?</h2>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Telecomlijn verkoopt uw gegevens nooit aan derden. Wij delen uw gegevens alleen met:</p>
  <h3 class="mt-6 text-base font-bold">Verwerkers (subverwerkers)</h3>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Partijen die namens ons gegevens verwerken, zoals onze netwerkpartners, hostingproviders en softwareleveranciers. Met deze partijen hebben wij verwerkersovereenkomsten gesloten.</p>
  <h3 class="mt-6 text-base font-bold">Netwerkoperators</h3>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Voor het leveren van telefonie- en internetdiensten werken wij samen met netwerkoperators. Deze ontvangen alleen de gegevens die noodzakelijk zijn voor de dienstverlening.</p>
  <h3 class="mt-6 text-base font-bold">Overheidsinstanties</h3>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Indien wij hiertoe wettelijk verplicht zijn, verstrekken wij gegevens aan bevoegde autoriteiten.</p>
  <p class="mt-3 text-[15px] leading-relaxed text-soft"><strong class="font-semibold text-ink">Doorgifte buiten de EER:</strong> Wij streven ernaar uw gegevens binnen de Europese Economische Ruimte (EER) te verwerken. Indien doorgifte naar landen buiten de EER noodzakelijk is, zorgen wij voor passende waarborgen conform de AVG (zoals Standard Contractual Clauses).</p>
</section>
<section id="hoe-beveiligen-wij-uw-gegevens" class="rise scroll-mt-24 rounded-2xl border border-line bg-panel p-6 shadow-panel sm:p-8" aria-labelledby="hoe-beveiligen-wij-uw-gegevens-h">
  <h2 id="hoe-beveiligen-wij-uw-gegevens-h" class="text-2xl font-bold tracking-tight">6. Hoe beveiligen wij uw gegevens?</h2>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Telecomlijn neemt passende technische en organisatorische maatregelen om uw persoonsgegevens te beschermen tegen onrechtmatige verwerking, verlies of beschadiging:</p>
  <ul class="mt-3 list-disc space-y-1.5 pl-5 text-[15px] leading-relaxed marker:text-lime">
    <li>SSL/TLS-versleuteling op al onze websites en portalen</li>
    <li>Versleutelde opslag van gevoelige gegevens</li>
    <li>Strenge toegangscontrole tot systemen en gegevens</li>
    <li>Regelmatige beveiligingsupdates en monitoring</li>
    <li>Training van medewerkers op het gebied van privacy en beveiliging</li>
    <li>Periodieke beveiligingsaudits en penetratietesten</li>
  </ul>
</section>
<section id="cookies-en-tracking" class="rise scroll-mt-24 rounded-2xl border border-line bg-panel p-6 shadow-panel sm:p-8" aria-labelledby="cookies-en-tracking-h">
  <h2 id="cookies-en-tracking-h" class="text-2xl font-bold tracking-tight">7. Cookies en tracking</h2>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Onze website maakt gebruik van cookies en vergelijkbare technologieën om de website goed te laten functioneren en om inzicht te krijgen in het gebruik van de website.</p>
  <h3 class="mt-6 text-base font-bold">Noodzakelijke cookies</h3>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Deze cookies zijn essentieel voor het functioneren van de website. Hiervoor is geen toestemming nodig.</p>
  <h3 class="mt-6 text-base font-bold">Analytische cookies</h3>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Wij gebruiken Google Analytics om inzicht te krijgen in het gebruik van onze website. Deze gegevens zijn geanonimiseerd en worden niet gedeeld met Google voor advertentiedoeleinden. Hiervoor vragen wij uw toestemming.</p>
  <h3 class="mt-6 text-base font-bold">Marketing cookies</h3>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Met uw toestemming kunnen wij marketing cookies plaatsen om relevante advertenties te tonen.</p>
  <p class="mt-3 text-[15px] leading-relaxed text-soft"><strong class="font-semibold text-ink">Cookie-instellingen wijzigen:</strong> U kunt uw cookievoorkeuren te allen tijde aanpassen via de cookie-instellingen onderaan elke pagina of via uw browserinstellingen.</p>
</section>
<section id="uw-rechten" class="rise scroll-mt-24 rounded-2xl border border-line bg-panel p-6 shadow-panel sm:p-8" aria-labelledby="uw-rechten-h">
  <h2 id="uw-rechten-h" class="text-2xl font-bold tracking-tight">8. Uw rechten</h2>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Op grond van de AVG heeft u de volgende rechten met betrekking tot uw persoonsgegevens:</p>
  <h3 class="mt-6 text-base font-bold">Recht op inzage</h3>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">U heeft het recht om te weten welke gegevens wij van u verwerken.</p>
  <h3 class="mt-6 text-base font-bold">Recht op rectificatie</h3>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">U kunt onjuiste of onvolledige gegevens laten corrigeren.</p>
  <h3 class="mt-6 text-base font-bold">Recht op verwijdering</h3>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">U kunt in bepaalde gevallen verzoeken om verwijdering van uw gegevens.</p>
  <h3 class="mt-6 text-base font-bold">Recht op overdraagbaarheid</h3>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">U kunt uw gegevens in een gangbaar formaat ontvangen.</p>
  <h3 class="mt-6 text-base font-bold">Recht van bezwaar</h3>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">U kunt bezwaar maken tegen verwerking op basis van gerechtvaardigd belang.</p>
  <h3 class="mt-6 text-base font-bold">Recht op beperking</h3>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">U kunt de verwerking tijdelijk laten stopzetten.</p>
  <p class="mt-3 text-[15px] leading-relaxed text-soft"><strong class="font-semibold text-ink">Verzoek indienen:</strong> U kunt uw verzoek indienen via office@telecomlijn.nl of per post naar Seinhuiswachter 12, 3034 KH Rotterdam. Wij reageren binnen 4 weken op uw verzoek. Voor identificatie kunnen wij u vragen om een kopie van uw identiteitsbewijs.</p>
</section>
<section id="klachten" class="rise scroll-mt-24 rounded-2xl border border-line bg-panel p-6 shadow-panel sm:p-8" aria-labelledby="klachten-h">
  <h2 id="klachten-h" class="text-2xl font-bold tracking-tight">9. Klachten</h2>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Bent u niet tevreden over hoe wij met uw persoonsgegevens omgaan? Neem dan eerst contact met ons op, zodat wij samen naar een oplossing kunnen zoeken.</p>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">U heeft ook het recht om een klacht in te dienen bij de Autoriteit Persoonsgegevens (AP), de Nederlandse toezichthouder op het gebied van privacy. Meer informatie vindt u op <a class="font-semibold underline decoration-lime decoration-2 underline-offset-4" href="https://www.autoriteitpersoonsgegevens.nl" target="_blank" rel="noopener">www.autoriteitpersoonsgegevens.nl</a>.</p>
</section>
<section id="contact" class="rise scroll-mt-24 rounded-2xl border border-line bg-panel p-6 shadow-panel sm:p-8" aria-labelledby="contact-h">
  <h2 id="contact-h" class="text-2xl font-bold tracking-tight">10. Contact</h2>
  <p class="mt-4 text-[15px] leading-relaxed text-soft">Telecomlijn ZakelijkSeinhuiswachter 123034 KH Rotterdam</p>
  <dl class="mt-5 grid gap-x-8 gap-y-4 text-[15px] sm:grid-cols-2">
    <div><dt class="text-soft">Heeft u vragen over dit privacybeleid of over de verwerking van uw persoonsgegevens? Neem dan contact met ons op</dt><dd class="mt-0.5"><span class="font-semibold">Adres</span></dd></div>
    <div><dt class="text-soft">E-mail</dt><dd class="mt-0.5"><a class="font-semibold underline decoration-lime decoration-2 underline-offset-4" href="mailto:office@telecomlijn.nl">office@telecomlijn.nl</a></dd></div>
    <div><dt class="text-soft">Telefoon</dt><dd class="mt-0.5"><a class="font-semibold underline decoration-lime decoration-2 underline-offset-4" href="tel:0102002000">010 200 2000</a></dd></div>
    <div><dt class="text-soft">KvK-nummer</dt><dd class="mt-0.5"><span class="font-semibold">65586492</span></dd></div>
  </dl>
</section>
<section id="wijzigingen" class="rise scroll-mt-24 rounded-2xl border border-line bg-panel p-6 shadow-panel sm:p-8" aria-labelledby="wijzigingen-h">
  <h2 id="wijzigingen-h" class="text-2xl font-bold tracking-tight">11. Wijzigingen</h2>
  <p class="mt-3 text-[15px] leading-relaxed text-soft">Telecomlijn behoudt zich het recht voor dit privacybeleid te wijzigen. Wijzigingen worden op deze pagina gepubliceerd. Wij raden u aan deze pagina regelmatig te raadplegen. Bij ingrijpende wijzigingen zullen wij u actief informeren.</p>
  <div class="mt-6 rounded-xl border border-line bg-butter-50 p-4"><h3 class="text-base font-bold">Gerelateerde documenten</h3><div class="mt-2 flex flex-wrap gap-x-6 gap-y-2 text-sm"><a class="font-semibold underline decoration-lime decoration-2 underline-offset-4" href="https://telecomlijn.nl/algemene-voorwaarden/" target="_blank" rel="noopener">Algemene Voorwaarden →</a><a class="font-semibold underline decoration-lime decoration-2 underline-offset-4" href="https://telecomlijn.nl/disclaimer/" target="_blank" rel="noopener">Disclaimer →</a></div></div>
</section>
    </div>
  </div>
</main>

<footer class="border-t border-line px-4 py-8 text-center text-xs text-soft sm:px-8">
  <p>© <?= date('Y') ?> Telecomlijn Zakelijk · KvK 65586492 · Seinhuiswachter 12, 3034 KH Rotterdam · <a class="font-semibold text-ink underline decoration-lime decoration-2 underline-offset-4" href="mailto:office@telecomlijn.nl">office@telecomlijn.nl</a></p>
</footer>
<script src="assets/motion.js" defer></script>
<div class="fixed bottom-4 right-4 z-20"><?= theme_toggle_button('grid h-10 w-10 place-items-center rounded-xl border border-line bg-panel hover:bg-ink/10') ?></div>
<?= theme_toggle_script() ?>
</body>
</html>
