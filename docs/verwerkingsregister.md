# Verwerkingsregister: beheerportaal Telecomlijn Admin

> **Concept.** Opgesteld op basis van het privacybeleid op https://telecomlijn.nl/privacybeleid/ (laatst bijgewerkt: december 2025). Laat dit toetsen door een jurist of de functionaris gegevensbescherming voordat het als officieel register wordt gebruikt. Dit is geen juridisch advies.

Verwerkingsverantwoordelijke: Telecomlijn Zakelijk, Seinhuiswachter 12, 3034 KH Rotterdam, KvK 65586492, office@telecomlijn.nl, 010 200 2000.

| # | Verwerking | Persoonsgegevens | Doel | Grondslag (zoals in het beleid) | Bewaartermijn | Ontvangers |
|---|---|---|---|---|---|---|
| 1 | Beheerdersaccounts | Naam, gebruikersnaam, e-mailadres, wachtwoord-hash (Argon2id) | Toegang tot het beheerportaal | Gerechtvaardigd belang (beveiliging) | Zolang het account actief is | Geen |
| 2 | Gebruikersbestand (klanten en gebruikers) | Naam, e-mailadres, rol, land, laatst gezien, aantal logins | Het leveren en beheren van de dienst | Uitvoering overeenkomst (art. 6 lid 1 sub b AVG) | Looptijd overeenkomst, daarna 2 jaar | Hosting- en softwareleveranciers (verwerkers) |
| 3 | Sessies / verbindingen | IP-adres, apparaat, plaats, protocol, tijden | Beheer en beveiliging van verbindingen | Gerechtvaardigd belang: fraudepreventie en netwerkbeveiliging (art. 6 lid 1 sub f) | Tot de sessie wordt beëindigd | Geen |
| 4 | Activiteitenlogboek | Account of e-mailadres van de actor, actie, IP-adres, tijdstip | Beveiliging, controle en verantwoording | Gerechtvaardigd belang (art. 6 lid 1 sub f) | **12 maanden**, daarna automatisch verwijderd (`bin/purge.php`) | Geen |
| 5 | Inlogpogingen en snelheidsbegrenzing | IP-adres, ingevoerde gebruikersnaam (alleen als die op een gebruikersnaam lijkt), tijdstip | Bescherming tegen aanvallen | Gerechtvaardigd belang (art. 6 lid 1 sub f) | 24 uur (inlogpogingen), 1 uur (snelheidsbegrenzing) | Geen |
| 6 | Facturen en betalingen | Naam en adres van de klant, e-mailadres, factuurregels, betalingen | Facturering, fiscale administratie | Wettelijke verplichting (art. 6 lid 1 sub c) | **7 jaar** (fiscale bewaarplicht), daarna automatisch verwijderd | Boekhouder, belastingdienst indien wettelijk verplicht |
| 7 | Vertaalfunctie (Google Translate) | De tekst van de pagina, inclusief zichtbare namen, e-mailadressen en IP-adressen | Interface in de taal van de gebruiker | **Toestemming** (art. 6 lid 1 sub a), vooraf gevraagd, intrekbaar via de voettekst | Toestemming wordt lokaal in de browser bewaard tot intrekking | Google (mogelijk verwerking buiten de EER) |
| 8 | Gesprekken en berichten (3CX, Yeastar, Twilio) | Telefoonnummers, tijdstip, duur, status; berichttekst alleen als dat expliciet is ingeschakeld (standaard uit) | Overzicht van klantcontact per bedrijf en persoon | Gerechtvaardigd belang (art. 6 lid 1 sub f); uitvoering overeenkomst | **12 maanden**, daarna automatisch verwijderd | Telefoonprovider (3CX/Yeastar-beheerder) en Twilio als verwerker; verwerkersovereenkomst nodig |
| 9 | Korte links (Short.io) | Doeladres, titel, gekoppeld bedrijf | Korte links delen met klanten | Gerechtvaardigd belang | Tot verwijdering | Short.io als verwerker; verwerkersovereenkomst nodig |
| 10 | Supportmailbox (e-mail naar ticket) | E-mailadres en naam van de afzender, onderwerp, de tekst van het bericht (bijlagen worden niet bewaard; alleen de eerste 512 KB wordt gelezen); het wachtwoord van de mailbox staat versleuteld (AES-256-GCM) in de database | Berichten aan het supportadres omzetten in tickets en antwoorden in het juiste ticket zetten | Gerechtvaardigd belang (klantenservice); uitvoering overeenkomst | De lijst van gelezen berichten (afzender, onderwerp, uitkomst): **12 maanden**, daarna automatisch verwijderd (`bin/purge.php`). De tekst staat in het ticket en volgt de bewaartermijn van tickets (zie punt 11 en de openstaande punten) | De mailserver (IMAP) van de aanbieder van het supportadres; verwerkersovereenkomst nodig |
| 11 | Tickets | Onderwerp, beschrijving en reacties (kunnen persoonsgegevens bevatten die de klant zelf schrijft), gekoppeld bedrijf en contactpersoon, e-mailadres van de aanvrager bij een ticket per e-mail, tijdstippen voor de afspraken over reactie- en oplostijd (SLA) | Ondersteuning van klanten en het bewaken van afgesproken reactie- en oplostijden | Uitvoering overeenkomst; gerechtvaardigd belang | Er is **nog geen automatische bewaartermijn** (zie openstaande punten) | Geen; een antwoord per e-mail gaat naar de aanvrager via de mailserver van de verzender (bij SMTP-instellingen) |
| 12 | Offertes | Naam, adres en e-mailadres van de klant, offerteregels, uitkomst (geaccepteerd of afgewezen) en eventueel de reden | Offertes uitbrengen en opvolgen | Uitvoering overeenkomst (voorbereiding); gerechtvaardigd belang | Er is **nog geen automatische bewaartermijn** (zie openstaande punten); een offerte die een factuur wordt, volgt de 7 jaar van de factuur | Geen |
| 13 | Contracten en abonnementen | Bedrijf, diensten, prijzen, begin- en einddatum, afspraken over verlenging en facturering | Beheer van lopende abonnementen en factureren per periode | Uitvoering overeenkomst | Looptijd van de overeenkomst; daarna tot verwijdering (zie openstaande punten). Facturen die uit een contract voortkomen volgen de 7 jaar van de factuur | Geen |

## Cookies en lokale opslag in het beheerportaal

| Naam | Type | Doel | Toestemming nodig? |
|---|---|---|---|
| `ops_console` (`__Host-ops_console` onder HTTPS) | Cookie, sessie | Ingelogd blijven | Nee, strikt noodzakelijk |
| `googtrans` | Cookie | Onthoudt de gekozen vertaaltaal; wordt alleen gezet na toestemming | Ja, en dus pas na akkoord |
| `translateConsent` | localStorage | Bewijs van de gegeven toestemming | Nee, nodig om de keuze vast te leggen |
| `sbPinned`, `sbSeen` | localStorage | Voorkeur voor de zijbalk | Nee, functionele voorkeur |
| `navY` | sessionStorage | Positie van het menu bij paginawissel | Nee, functioneel |

Er worden **geen** analytische, marketing- of socialemedia-cookies of trackers gebruikt in het beheerportaal.

## Rechten van betrokkenen

Verzoeken (inzage, rectificatie, verwijdering, overdraagbaarheid, bezwaar, beperking) worden binnen 4 weken afgehandeld, zoals in het privacybeleid staat. In het portaal: **Privacy requests** (`privacy/privacy.php`) toont wat er over een e-mailadres is vastgelegd (ook de tickets en reacties die iemand per e-mail heeft gestuurd, en offertes aan dat adres), maakt een JSON-export en anonimiseert een gebruiker. Voor iemand die alleen het supportadres heeft gemaild en geen gebruikersaccount heeft, kan het adres uit de tickets, de reacties en de lijst van gelezen berichten worden gehaald; de tickets zelf blijven als bedrijfsgegevens bestaan. Wat iemand zelf in een ticket heeft geschreven wordt niet automatisch gewist en moet met de hand worden nagelopen. Facturen worden niet gewist vanwege de bewaarplicht van 7 jaar.

## Openstaande punten (niet uit de code te halen)

- Verwerkersovereenkomsten met hosting- en softwareleveranciers, en met de aanbieder van de supportmailbox (IMAP).
- Doorgiftegrondslag voor Google (bijvoorbeeld EU-US Data Privacy Framework of standaardcontractbepalingen). Laat dit door een jurist bevestigen.
- Opnemen van het beheerportaal en de vertaalfunctie in het officiële privacybeleid op telecomlijn.nl.
- Bewaartermijn voor tickets, offertes en afgelopen contracten: de code verwijdert ze niet automatisch. Kies een termijn (bijvoorbeeld 2 of 7 jaar na afsluiting) en laat de code daarop aanpassen; tot die tijd blijven ze bestaan totdat iemand ze verwijdert.
- Een klant die per e-mail contact opneemt, weet mogelijk niet dat zijn bericht in een systeem wordt bewaard en dat hij een bevestiging krijgt. Overweeg een korte tekst in het privacybeleid en onder de bevestigingsmail.
- De SLA telt 24 uur per dag; spreek met klanten af hoe zij de afspraken lezen (kantooruren, feestdagen).
