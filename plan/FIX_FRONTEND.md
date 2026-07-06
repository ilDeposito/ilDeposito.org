Esegui i seguenti fix di frontend

## Interventi vari
- nella pagina /canti nell'head-title c'è un link "Tutti i canti dell'archivio". Il font di questo link è troppo piccolo, ingrandiamolo. Task da eseguire anche nella pagina /autori, /eventi, /autori/[slug], /periodi/[slug]. In quest'ultimo caso il link appare solo quando l'autore ha più di 10 canti

- nella pagina /traduzioni, nella singola card appare il link alla traduzione e sotto il l titolo del canto originale. Aggiungi la label "Canto originale: " prima del canto originale

- nella pagina /autori/elenco gli autori sono visualizzati in ordine di titolo. Devono essere invece visualizzati in ordine alfabetico in base al valore del campo field_cognome

## Interventi sul dettaglio del canto (/canti/[slug])
- sotto il tasto c'è un link (icona + label) per il download del pdf. Spostalo da destra a sinistra, sotto il tasto (lasciando un po' di margine dalla fine del testo)

- la scheda canto, in basso, deve avere i campi nel seguente ordine: autori testo, autori musica, periodo, tematiche, lingua, tags, video

## Interventi sulla pagina del singolo periodo (/periodi/[slug])
- in fondo alla pagina c'è un link "Tutti i contenuti del periodo $title". Aggiungendo il nome del periodo i link diventa lunghissimo, deve essere solo "Tutti i contenuti del periodo"

## Interventi sugli eventi 
- nella pagina /eventi e in /eventi/[slug] se si fa scorrere la pagina la mappa sta sopra la navbar che invece dovrebbe essere sticked
- nel singolo evento, sotto la data dell'evento, c'è sempre il link alla storia cantata. Quel link deve essere temizzato come gli altri link che stanno nell'head-title, quindi sfondo rosso e scritta bianca
