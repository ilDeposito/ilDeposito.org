# Piano — Modalità Leggio per i canti

## Obiettivo

Nelle pagine `/canti/[slug]` aggiungere un CTA **Apri il leggio**, posto prima del blocco testo/tab. Il CTA apre una finestra modale che copre l'intero viewport, senza header, barra evento o footer, e mostra soltanto titolo, toolbar e testo del canto. Se sono disponibili gli accordi, il leggio si apre direttamente con la versione con accordi.

L'intervento è esclusivamente frontend: `CantoDetail` espone già `testo` e `accordi`, quindi non richiede cambiamenti a Drupal, JSON:API, mapper né rigenerazione dei PDF.

## Stato attuale rilevato

- Il rendering della pagina è in `frontend/src/components/detail/CantoDetailView.astro`; sia la rotta pubblica sia l'anteprima riutilizzano questo componente.
- `frontend/src/scripts/song-lyrics.js` gestisce tab testo/accordi e trasposizione delle note italiane; la logica va estratta o resa riutilizzabile, invece di duplicarla nel leggio.
- Il componente `frontend/src/components/base/Icon.astro` centralizza gli SVG di Phosphor. Gli asset necessari esistono già nella versione installata di `@phosphor-icons/core`.
- Il tema corrente dispone solo della variante chiara `ildeposito`; il tema scuro del leggio deve quindi essere locale al dialogo, non un cambio di `data-theme` sull'intero documento.

## Decisioni confermate

- **Sempre attivo**: è il blocco dello standby dello schermo tramite Wake Lock API, con priorità ai browser mobile Android e iOS. Il piano include fallback non bloccante per browser non supportati, riacquisizione dopo il ritorno in primo piano e rilascio alla chiusura. Il comportamento desktop sarà verificato, ma non condiziona la funzionalità mobile.
- **Nessun URL e nessuna persistenza**: il leggio è aperto solo dal pulsante/link della pagina canto; le preferenze vivono unicamente durante quell'apertura e non vengono salvate in `localStorage`.
- **Visibilità accordi**: il comando alterna il campo `accordi` (con accordi visibili) e il campo `testo` pulito, senza tentare di eliminare righe dal primo. Il valore di trasposizione resta nello stato della stessa apertura: quando si torna agli accordi, questi vengono ridisegnati nella tonalità già selezionata.
- **Auto-scroll**: pulsante toggle avvia/pausa, con comandi `−` / `+` per rallentare/accelerare. Gli intervalli esatti saranno affinati dopo una prima prova; lo scorrimento si ferma a fine contenuto e alla chiusura.
- **Notazione**: resta esclusivamente italiana in questa prima versione. Non si implementa conversione italiano ↔ inglese.
- **Tema**: il sito sottostante non cambia; il dialogo si apre chiaro e il relativo comando passa tra chiaro e scuro solo nel leggio.
- **Titolo**: resta fisso nella toolbar; scorre soltanto il contenuto.
- **Fullscreen e desktop**: usare un `<dialog>` a `100dvh`/`100vw`, senza Fullscreen API. È la soluzione più affidabile su Android e iOS, evita il consenso del browser e gestisce correttamente la safe area. Su desktop titolo e testo saranno contenuti in un wrapper centrato della stessa larghezza del container principale del sito (`max-w-6xl`), con il testo a una larghezza di lettura più confortevole; la superficie del dialogo resta comunque fullscreen.

## Implementazione proposta

1. Creare `frontend/src/components/ui/LecternModal.astro`, ricevendo `titolo`, `testo` e `accordi` dal dettaglio canto. Il componente conterrà un elemento `<dialog>` fullscreen, il CTA esterno e un custom element vanilla dedicato; il dialogo resta nel `BaseLayout`, ma il suo contenuto non eredita alcun elemento di header/footer.

2. Aggiornare `CantoDetailView.astro` per renderizzare il CTA immediatamente prima della sezione `<!-- Testo / Accordi -->`, passargli i dati del canto e caricare lo script del leggio. Il CTA sarà disponibile anche per i canti senza accordi; nel leggio, in quel caso, saranno omessi i controlli dipendenti dagli accordi.

3. Estrarre dal corrente `song-lyrics.js` le utility pure per riconoscimento, trasposizione, escaping e rendering delle righe di accordi in un modulo riutilizzabile (ad esempio `frontend/src/scripts/chords.js`). Riutilizzare integralmente la logica di trasposizione già presente nella pagina canto, senza modificarne le regole o introdurre conversione della notazione. L'estrazione servirà a:
   - trasposizione coerente fino a 12 semitoni e reset alla tonalità originale;
   - applicare la stessa resa delle righe di accordi nel leggio;
   - conservare l'allineamento monospace esistente.
   `song-lyrics.js` continuerà a usare queste utility: così tab e traspositore della pagina normale non cambiano comportamento.

4. Implementare `frontend/src/scripts/lectern-modal.js` come custom element senza framework/hydration. Lo stato sarà interno alla singola apertura: vista testo/accordi, semitoni, tema, dimensione font, auto-scroll, velocità e Wake Lock. Il toggle accordi passerà dal campo `accordi` trasposto al campo `testo` pulito; non azzererà i semitoni, che saranno riapplicati al ritorno agli accordi. All'apertura inizializzerà il contenuto (accordi se disponibili), porterà l'area di lettura in alto e posizionerà il focus sul pulsante di chiusura; alla chiusura fermerà animazione e Wake Lock, eliminerà lo stato e ripristinerà il focus al CTA.

5. Costruire una toolbar accessibile, con pulsanti etichettati via `aria-label`, stato esposto con `aria-pressed`, tooltip testuale al passaggio del mouse e controlli da tastiera. Le icone Phosphor saranno aggiunte unicamente a `Icon.astro`:

   | Funzione | Icona proposta |
   | --- | --- |
   | Apri leggio | `monitor` |
   | Chiudi | `x` |
   | Mostra/nascondi accordi | `eye` / `eye-slash` |
   | Sempre attivo | `push-pin` / `push-pin-slash` |
   | Tema chiaro/scuro | `sun` / `moon` |
   | Trasposizione | `arrows-clockwise` con pulsanti `minus` / `plus` |
   | Auto-scroll | `play` / `pause`, velocità `minus` / `plus` |
   | Dimensione testo | `text-aa`, dimensione `minus` / `plus` |

6. Aggiungere CSS locale/scoped nel componente per il dialogo a viewport intero, toolbar aderente e utilizzabile su schermi piccoli, safe area mobile, area di lettura con overflow verticale, font monospace e una variante scura con contrasto AA. Su desktop, toolbar e contenuto sono centrati nel container principale (`max-w-6xl`) e il testo resta limitato a una larghezza di lettura. Rispettare `prefers-reduced-motion`: auto-scroll disattivato di default per chi lo richiede e messaggio/stato non invasivo se l'utente lo avvia deliberatamente.

7. Test e verifica:
   - aggiungere test unitari Node per parser, trasposizione e reset, verificando che testo e sorgente accordi non vengano alterati;
   - ampliare `frontend/tests/e2e/canto.spec.ts` con un canto che abbia accordi: apertura/chiusura, dialogo fullscreen, accordi come vista iniziale, toggle accordi, trasposizione, tema, font e controllo dell'auto-scroll;
   - verificare anche un canto senza accordi e la rotta `/preview/[uuid]`, perché condividono `CantoDetailView`;
   - eseguire `npm run build`, i test unitari e `npm run test:e2e`; controllare manualmente layout e focus su desktop e viewport mobile.

## Criteri di accettazione

- Ogni dettaglio canto espone un CTA leggio prima del contenuto musicale e apre/chiude una modale senza elementi globali del sito.
- Il titolo e il contenuto corretto sono leggibili; quando ci sono accordi, questi sono visibili all'apertura.
- Nascondendo gli accordi si visualizza il campo testo pulito; riesponendoli resta applicata l'eventuale trasposizione della stessa apertura.
- Ogni funzione prevista è operabile con mouse, touch e tastiera, mostra il proprio stato e usa un'icona Phosphor coerente.
- La trasposizione agisce solo sugli accordi e non altera il testo o il contenuto originale della pagina; la notazione resta italiana.
- Lo scrolling si arresta sempre a chiusura, a fine contenuto e quando viene premuto pausa; Wake Lock non resta mai acquisito dopo la chiusura.
- Non vengono introdotte nuove dipendenze né modifiche al backend.
