# Secretary Control Panel UX

## Arbeidsflyt

Secretary er tilgjengelig som et flytende panel i hele Statamic. Redaktøren skal kunne fullføre den daglige samtalen og endringskontrollen uten å forlate siden hen arbeider med. Den separate Secretary-siden brukes bare til administrativt oppsett og historikk, og lenkes ikke fra arbeidsflyten i panelet.

Panelet følger alltid siden redaktøren ser på:

1. Hver entry har sin egen samtale, status og sitt eget ulagrede komposisjonsutkast.
2. Når redaktøren navigerer til en annen entry, bytter panelet automatisk til den entryens samtale.
3. En allerede startet jobb beholder den opprinnelige entryen som mål og fortsetter i bakgrunnen.
4. Når redaktøren går tilbake, gjenopprettes samtale, jobbstatus og ulagret tekst for den entryen.
5. Ulagret tekst lagres lokalt per innlogget bruker og entry, slik at den også overlever lukking og gjenåpning av fanen.

## Overgang fra e-post til Statamic

Et Secretary-utkast og samtalen som opprettet det er én sammenhengende arbeidsflate:

- Hovedknappen «Åpne utkastet i Statamic» i e-postsvaret åpner entryen med den eksakte samtalen.
- Panelet åpnes automatisk når en redaktør går inn på en entry med et aktivt Secretary-utkast, også uten spesiallenken.
- En aktiv utkasttråd prioriteres foran nyere, løse samtaler om samme entry.
- E-posthistorikken vises i panelet, og nye CP-meldinger lagres i den samme samtalen.
- Panelet forklarer kanalovergangen kort: hele dialogen følger med, og redaktøren kan fortsette uten å starte på nytt.
- «Åpne utkast» fra panelet beholder conversation-ID i URL-en, slik at panelet forblir åpent etter navigasjonen.

Når en redaktør starter en samtale fra en åpen entry:

1. Serveren løser entryen fra kontrollpaneladressen og kontrollerer brukerens tilgang.
2. Samtalen lagrer entry-ID, collection og site som avgrenset kontekst.
3. «Denne siden» betyr den konkrete entryen, men agenten må fortsatt lese den gjennom de vanlige, tilgangskontrollerte verktøyene.
4. Samtalen viser en tydelig lenke tilbake til entryen.

Hvis redaktøren sist jobbet i et konkret felt, valideres field handle mot entryens faktiske blueprint. Bard- og Replicator-kontekst tas med når kontrollpanelet eksponerer set type og indeks. Agenten må fortsatt lese entryen og får aldri feltinnhold som privilegerte instruksjoner.

`@` i komposisjonsfeltet søker bare i entries den innloggede brukeren kan se. Det valgte innholdet settes inn som en eksplisitt ID-referanse, slik at titteltekst aldri brukes som identitet.

## Felt- og modulkontroll

Et endringskort deler patchen i gjennomgåbare mål:

- vanlige fields vises som egne før/etter-par;
- endrede Bard- og Replicator-sets vises som egne moduler;
- «Behold» og «Avvis» bygger det eksisterende utkastet på nytt umiddelbart;
- en fingerprint-konflikt stopper handlingen hvis en redaktør har endret utkastet i mellomtiden.

Valgene er kontroll av innholdet i utkastet, ikke en ny publiseringsgodkjenning. Publisering er fortsatt den separate eksisterende handlingen.

## Forhåndsvisning

«Sammenlign live og utkast» oppretter en tidsavgrenset Statamic Live Preview-token på forespørsel. Publisert side og working copy vises side ved side på store skjermer og som tilgjengelige faner på smale skjermer. Ingen preview-token lages under polling.

## Redaksjonell guide og systemstatus

Fullvisningen har to sekundære, sammenleggbare verktøy:

- per-site målgruppe, tone, terminologi og formuleringer som skal unngås;
- en visuell utgave av de samme, secret-frie kontrollene som `secretary:doctor`.

De ligger utenfor den primære samtaleflyten for å holde daglig redaktørarbeid rolig.

## Tilstander

Grensesnittet skiller tydelig mellom:

- sendt melding;
- Secretary jobber;
- endring klar som utkast;
- publisert;
- endringen kunne ikke lagres.

Utkast vises som egne arbeidskort med direkte lenke til Statamic. Publisering er en eksplisitt handling. Fullvisningen ber om bekreftelse før publisering.

Redaktøren kan sende oppfølgingsmeldinger mens Secretary jobber. Første ubehandlede melding vises som aktiv, senere meldinger får et synlig kønummer og behandles i riktig rekkefølge. Dette gjelder både det flytende panelet og fullvisningen.

## Oppdatering uten refresh

Meldinger, arbeidsstatus og utkastskort oppdateres i panelet med polling uten nettleser-refresh. En allerede åpen Statamic-redigeringsform overskrives ikke automatisk når utkastet blir klart, fordi redaktøren kan ha ulagrede lokale endringer. Redaktøren åpner i stedet det ferske utkastet eksplisitt fra endringskortet.

## Prinsipper

- Bruk Statamics komponenter, farger, fokusmarkering og typografiske hierarki.
- Hold tekniske detaljer borte fra den daglige arbeidsflyten.
- Vis e-post og kontrollpanel som to innganger til samme samtalehistorikk.
- Ikke skjul feil, ventetid eller publiseringsstatus.
- Bevar tastaturnavigasjon, synlige fokusmarkeringer, redusert bevegelse og smale skjermer.
