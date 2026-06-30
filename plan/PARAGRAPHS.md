Devi realizzare il template per il tipo di contenuto "pagina".
Iniziamo a definire alcune cose:

- lo slug deve essere quello definito nell'alias del nodo drupal
- il titolo della pagina è il campo "title" del nodo
- nell'head title, sotto il titolo della pagina, devi stampare il valore del campo "descrizione_header"

Il tipo di contenuto ha un layout dinamico basato sul modulo contrib drupal "paragraphs".
Di base tutto il contenuto della pagina sta dentro il container della pagina.

Tutti i tipi i tipi di paragraph, a parte "griglia" si prendono il 100% dello spazio in cui sono inseriti.
Ogni paragraph type ha un padding top e bottom uguale, abbastanza sostanzioso

Di seguito le specifiche per ogni tipo di paragraph

### Testo ###
Il paragraph type "testo" ha un solo campo, field_testo, che ha un testo nel formato filtered html.
Deve essere stampato così come è al 100% della larghezza del contenitore in cui è inserito

### Citazione ###
Il paragraph type "citazione" ha due campi: field_testo e field_fonte.
Il campo "field_testo" deve essere visualizzato con il font che si usa per il titolo, italico con un font più grande rispetto al testo normale, allineato al centro.
Devo avere, in alto a sinistra e in basso a destra, delle virgolette, prendi le giuste icone da phosphors.
Il campo "field fonte" va inserito sotto la citazione, dimensione testo normale, nessuno stile particolare.
Valuta tu se le virgolette devono essere di un colore particolare.
La citazione e la font devono essere larghe al 85% del container dove sono inseriti (il blocco totale invece al 100&)

### Immagine ###
Tutto il blocco è largo al 100% del container in cui è inserito, ma l'immagine è larga l'85 del container.
è gestita dal campo field_media (che a sua volta è un rerefence verso un'immagine).
Deve essere ritagliata con una propozione 800px (in larghezza) e 350px (in altezza).
Gestisci il responsive dell'immagine affinché carichi il giusto ritaglio a 
seconda del breakpoint.
Sotto l'immagine, se compilato, il campo "descrizione_immagine", centrato (come il campo "fonte" della citazione)

### Card ###
Il paragraph type card serve per creare le classiche CTA verso pagine interne.
Sarà quasi sempre inserito dentro una griglia (vedi paragraph type successivo).
Si prende quindi il 100% del container in cui è inserito.
I campi sono i seguenti:
- titolo: il titolo della card, da mettere in alto nella card, con font per i titoli
- testo: la descrizione della card, il testo che descrive la pagina verso cui linka.
  Deve essere messo sotto il titolo
- link: un campo tipo "link" di drupal con un url e un testo del link. L'url sarà un valore assoluto (tipo "/canti") che deve puntare a quella rotta di astro. è cura
del redattore linkare a indirizzi che esistono su Astro
Fai tu una proposta di temizzazione di questo oggetto, anche giocando con i colori
che stiamo usando sul sito (ma senza prevedere sfondo rosso o nero).
I bottoni e i link senza bordo.
Sia il titolo che il campo link devono puntare all'url definiito nella card.

### Griglia ###
Questo paragraph type crea un nuovo container diviso in colonne.
Ha due campi
- "colonne" indica in quante colonne deve essere diviso in blocco e in che percentuale.
le opzioni sono:
        -   due_50_50 (due colonne, ognuna al 50%)
        -   due_33_66 (due colonne, la prima al 33, la seconda al 66%)
        -   due_66_33 (due colonne, la prima al 66, la seconda al 33%)
        -   tre_33_33_33 (tre colonne, tutte al 33%)

- "grid_item": un campo di tipo paragraph che dentro può avere tutti i paragraph type (a parte "griglia"). I paragraphs messi qui dentro si prendono il 100% dello spazio, 
infilandosi di fatto dentro le colonne

Analizza le config di drupal, analizza i template astro e fai domande per qualsiasi
cosa non chiara.