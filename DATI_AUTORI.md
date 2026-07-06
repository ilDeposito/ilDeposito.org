# Dati autori — batch 1 (40 autori con nome+cognome più visti)

Ricerca automatica (web) di anno di nascita, anno di morte e link Wikipedia per i 40 autori con `field_nome` valorizzato più visti (`field_visualizzazioni`). Da validare a mano prima della scrittura in Drupal.

Mapping campi Drupal:
- `anno_nascita` → `field_anno_di_nascita` (integer)
- `anno_morte` → `field_anno_di_morte` (integer, vuoto se vivente)
- `wikipedia` → `field_links` (usato come sameAs nello schema.org)

⚠️ **Righe da ricontrollare con più attenzione** (decessi molto recenti 2020-2025, nessuna pagina Wikipedia, o identità/ruolo incerti): vedi colonna note.

| nid | nome | viste | anno_nascita | anno_morte | wikipedia | note |
|---|---|---|---|---|---|---|
| 253 | Fausto Amodei | 12760 | 1934 | 2025 | https://it.wikipedia.org/wiki/Fausto_Amodei | ⚠️ verificare decesso 2025 |
| 300 | Pietro Gori | 11493 | 1865 | 1911 | https://it.wikipedia.org/wiki/Pietro_Gori | |
| 66 | Paolo Pietrangeli | 11249 | 1945 | 2021 | https://it.wikipedia.org/wiki/Paolo_Pietrangeli | ⚠️ verificare decesso 2021 |
| 346 | Ivan Della Mea | 9220 | 1940 | 2009 | https://it.wikipedia.org/wiki/Ivan_Della_Mea | |
| 267 | Italo Calvino | 6510 | 1923 | 1985 | https://it.wikipedia.org/wiki/Italo_Calvino | Scrittore, non cantautore in senso stretto; legato al gruppo Cantacronache come paroliere |
| 223 | Alberto D'Amico | 6003 | 1943 | 2020 | https://it.wikipedia.org/wiki/Alberto_D'Amico | ⚠️ verificare decesso 2020 |
| 115 | Giovanna Marini | 4327 | 1937 | 2024 | https://it.wikipedia.org/wiki/Giovanna_Marini | ⚠️ verificare decesso 2024 |
| 84 | Gianfranco Manfredi | 4153 | 1948 | 2025 | https://it.wikipedia.org/wiki/Gianfranco_Manfredi | ⚠️ verificare decesso 2025 — rischio di omonimia (fumettista/musicista) |
| 193 | Felice Cascione | 3515 | 1918 | 1944 | https://it.wikipedia.org/wiki/Felice_Cascione | |
| 259 | Gualtiero Bertelli | 3370 | 1944 | vivente | https://it.wikipedia.org/wiki/Gualtiero_Bertelli | |
| 186 | Matteo Salvatore | 2869 | 1925 | 2005 | https://it.wikipedia.org/wiki/Matteo_Salvatore | |
| 86 | Franco Madau | 2786 | 1953 | vivente | nessuna | Nessuna pagina Wikipedia dedicata (esiste solo "Madau Dischi", l'etichetta da lui fondata) |
| 167 | Otello Profazio | 2763 | 1934 | 2023 | https://it.wikipedia.org/wiki/Otello_Profazio | |
| 247 | Alfredo Bandelli | 2475 | 1945 | 1994 | https://it.wikipedia.org/wiki/Alfredo_Bandelli | |
| 344 | Enzo Del Re | 2438 | 1944 | 2011 | https://it.wikipedia.org/wiki/Enzo_Del_Re | |
| 135 | Franco Trincale | 2373 | 1935 | vivente | https://it.wikipedia.org/wiki/Franco_Trincale | Fonti set. 2025: vivente, ricoverato in RSA con la moglie |
| 52 | Raffaele Mario Offidani | 2067 | 1890 | 1968 | https://it.wikipedia.org/wiki/Raffaele_Mario_Offidani | |
| 150 | Filippo Turati | 1994 | 1857 | 1932 | https://it.wikipedia.org/wiki/Filippo_Turati | Politico socialista, non cantautore, ma autore di testi di canti sociali/politici |
| 260 | Pierangelo Bertoli | 1973 | 1942 | 2002 | https://it.wikipedia.org/wiki/Pierangelo_Bertoli | |
| 353 | Dario Fo | 1930 | 1926 | 2016 | https://it.wikipedia.org/wiki/Dario_Fo | |
| 136 | Armando Trovajoli | 1739 | 1917 | 2013 | https://it.wikipedia.org/wiki/Armando_Trovajoli | |
| 92 | Claudio Lolli | 1635 | 1950 | 2018 | https://it.wikipedia.org/wiki/Claudio_Lolli | |
| 329 | Sergio Liberovici | 1624 | 1930 | 1991 | https://it.wikipedia.org/wiki/Sergio_Liberovici | |
| 166 | Carlos Puebla | 1593 | 1917 | 1989 | https://it.wikipedia.org/wiki/Carlos_Puebla | |
| 159 | Nuto Revelli | 1510 | 1919 | 2004 | https://it.wikipedia.org/wiki/Nuto_Revelli | |
| 314 | Alessio Lega | 1479 | 1972 | vivente | https://it.wikipedia.org/wiki/Alessio_Lega | |
| 111 | Pino Masi | 1335 | 1946 | vivente | https://it.wikipedia.org/wiki/Pino_Masi | |
| 184 | Nanni Svampa | 1332 | 1938 | 2017 | https://it.wikipedia.org/wiki/Nanni_Svampa | |
| 270 | Bertold Brecht | 1325 | 1898 | 1956 | https://it.wikipedia.org/wiki/Bertolt_Brecht | Nome corretto "Bertolt Brecht" (in Drupal è scritto "Bertold") |
| 248 | Joan Baez | 1281 | 1941 | vivente | https://it.wikipedia.org/wiki/Joan_Baez | |
| 127 | Giorgio Strehler | 1258 | 1921 | 1997 | https://it.wikipedia.org/wiki/Giorgio_Strehler | Regista teatrale, non cantautore, ma legato alla canzone d'autore (collab. con Jannacci ecc.) |
| 41 | Violeta Parra | 1123 | 1917 | 1967 | https://it.wikipedia.org/wiki/Violeta_Parra | |
| 285 | Woody Guthrie | 1076 | 1912 | 1967 | https://it.wikipedia.org/wiki/Woody_Guthrie | |
| 47 | Gianni Nebbiosi | 1022 | 1944 | vivente | https://it.wikipedia.org/wiki/Gianni_Nebbiosi | |
| 369 | Franco Fortini | 1018 | 1917 | 1994 | https://it.wikipedia.org/wiki/Franco_Fortini | Pseudonimo di Franco Lattes; scrittore/poeta, testi usati nella canzone sociale italiana |
| 283 | Francesco "Ciccio" Giuffrida | 962 | non trovato | non trovato | nessuna | Cantastorie catanese ancora attivo (fonti antiwarsongs.org/ildeposito.org), nessun dato biografico affidabile trovato |
| 243 | E. Bergeret | 940 | non trovato | non trovato | nessuna | Pseudonimo, probabilmente Ettore Marroni (n. 23/07/1875), traduttore de "L'Internazionale" (1901); identità non confermata con certezza |
| 265 | Ignazio Buttitta | 935 | 1899 | 1997 | https://it.wikipedia.org/wiki/Ignazio_Buttitta | |
| 323 | Victor Jara | 881 | 1932 | 1973 | https://it.wikipedia.org/wiki/Victor_Jara | Assassinato durante il golpe cileno |
| 257 | Rudi Assuntino | 837 | 1941 | vivente | https://it.wikipedia.org/wiki/Rudi_Assuntino | |

## Riepilogo batch 1

- 40/40 autori processati
- 36 con pagina Wikipedia trovata, 2 senza (Franco Madau, Francesco "Ciccio" Giuffrida), 1 con identità incerta (E. Bergeret)
- 2 anno_nascita/morte non trovati: Francesco "Ciccio" Giuffrida, E. Bergeret
- Diverse note per figure che sono scrittori/politici/registi piuttosto che cantautori in senso stretto (Calvino, Turati, Strehler, Fortini) — presenti nell'archivio probabilmente come autori di testi

---

# Dati autori — batch 2 (autori dal 41° all'80° posto per visualizzazioni)

🚨 **Anomalia importante da verificare con la redazione**: per **Luciano Rossi (nid 170)** l'agente di ricerca non ha trovato una corrispondenza affidabile. ilDeposito attribuisce a "Luciano Rossi" la musica di *Dalle belle città* (inno della III Brigata Garibaldi Liguria, 1944), ma fonti indipendenti (ANPI, partigiano.net, ISRAL) attribuiscono la composizione ad **Angelo Rossi, detto "Lanfranco" (1924–2012)**. La voce it.wikipedia.org/wiki/Luciano_Rossi_(cantante) riguarda invece un cantautore pop romano (1945-2023, "Se mi lasci non vale"), incompatibile per età con la Resistenza — probabilmente non è la stessa persona. **Non ho compilato dati per questa riga**: potrebbe essere un errore di attribuzione già presente nel contenuto Drupal, da controllare prima di procedere.

| nid | nome | viste | anno_nascita | anno_morte | wikipedia | note |
|---|---|---|---|---|---|---|
| 200 | Fiorenzo Carpi | 819 | 1918 | 1997 | https://it.wikipedia.org/wiki/Fiorenzo_Carpi | |
| 197 | Ascanio Celestini | 772 | 1972 | vivente | https://it.wikipedia.org/wiki/Ascanio_Celestini | |
| 364 | Lucilla Galeazzi | 772 | 1950 | vivente | https://it.wikipedia.org/wiki/Lucilla_Galeazzi | |
| 255 | Franco Antonicelli | 759 | 1902 | 1974 | https://it.wikipedia.org/wiki/Franco_Antonicelli | |
| 176 | Leoncarlo Settimelli | 757 | 1937 | 2011 | https://it.wikipedia.org/wiki/Leoncarlo_Settimelli | |
| 321 | Enzo Jannacci | 755 | 1935 | 2013 | https://it.wikipedia.org/wiki/Enzo_Jannacci | |
| 49 | Paola Nicolazzi | 754 | 1933 | 2014 | nessuna | ⚠️ Nessuna voce Wikipedia; anno nascita da fonte secondaria (blog), non enciclopedica — verificare |
| 179 | Pete Seeger | 724 | 1919 | 2014 | https://it.wikipedia.org/wiki/Pete_Seeger | |
| 60 | Ennio Morricone | 714 | 1928 | 2020 | https://it.wikipedia.org/wiki/Ennio_Morricone | |
| 352 | Umberto Fiori | 655 | 1949 | vivente | https://it.wikipedia.org/wiki/Umberto_Fiori | |
| 271 | Georges Brassens | 616 | 1921 | 1981 | https://it.wikipedia.org/wiki/Georges_Brassens | |
| 338 | Leo Ferrè | 592 | 1916 | 1993 | https://it.wikipedia.org/wiki/L%C3%A9o_Ferr%C3%A9 | Nome corretto "Léo Ferré" (in Drupal è scritto "Leo Ferrè") |
| 331 | Sergio Endrigo | 588 | 1933 | 2005 | https://it.wikipedia.org/wiki/Sergio_Endrigo | |
| 242 | Roberto Benigni | 579 | 1952 | vivente | https://it.wikipedia.org/wiki/Roberto_Benigni | |
| 185 | Chicho Sánchez Ferlosio | 435 | 1940 | 2003 | https://it.wikipedia.org/wiki/Chicho_S%C3%A1nchez_Ferlosio | |
| 368 | Pardo Fornaciari | 434 | 1948 | vivente | nessuna | Nessuna pagina Wikipedia; dati da fonti secondarie (Wikitesti, CV pubblicato, Vernacoliere) |
| 128 | Michele Luciano Straniero | 408 | 1936 | 2000 | https://it.wikipedia.org/wiki/Michele_Straniero | |
| 240 | Dante Bartolini | 406 | 1909 | 1979 | https://de.wikipedia.org/wiki/Dante_Bartolini | Nessuna voce su it.wikipedia.org, solo in tedesco |
| 73 | Belgrado Pedrini | 403 | 1913 | 1979 | https://it.wikipedia.org/wiki/Belgrado_Pedrini | |
| 118 | Virgilio Savona | 399 | 1919 | 2009 | https://it.wikipedia.org/wiki/Virgilio_Savona | |
| 191 | Emilio Casalini | 387 | 1920 | 1944 | https://it.wikipedia.org/wiki/Emilio_Casalini_(partigiano) | Partigiano "Cini", fucilato nel 1944, autore testo "Dalle belle città". ⚠️ Esiste omonima voce "Emilio Casalini (giornalista)": persona diversa, non confondere |
| 238 | Anna Barile | 383 | 1949 | vivente | nessuna | Nessuna voce Wikipedia; anno nascita da fonte secondaria (antiwarsongs.org). Nome comune: attenzione a omonimi |
| 74 | Lino Patruno | 381 | 1935 | vivente | https://it.wikipedia.org/wiki/Lino_Patruno | Nome all'anagrafe Michele Patruno, fondatore de I Gufi |
| 411 | Gianni Rodari | 374 | 1920 | 1980 | https://it.wikipedia.org/wiki/Gianni_Rodari | |
| 313 | Antonietta Laterza | 370 | 1953 | vivente | https://it.wikipedia.org/wiki/Antonietta_Laterza | |
| 31 | Giorgio Gaber | 352 | 1939 | 2003 | https://it.wikipedia.org/wiki/Giorgio_Gaber | |
| 264 | Cicciu Busacca | 347 | 1925 | 1989 | https://en.wikipedia.org/wiki/Ciccio_Busacca | Nessuna voce in italiano, solo in inglese |
| 170 | Luciano Rossi | 336 | — | — | — | 🚨 Vedi anomalia segnalata sopra: possibile errore di attribuzione nel contenuto Drupal, non compilato |
| 22 | Angelo Caria | 332 | 1947 | 1996 | https://it.wikipedia.org/wiki/Angelo_Caria | Poeta/politico indipendentista sardo, non propriamente cantautore, ma autore di testi di canti di protesta |
| 226 | Paolo Ciarchi | 327 | 1942 | 2019 | https://it.wikipedia.org/wiki/Paolo_Ciarchi | Chitarrista, pilastro del Nuovo Canzoniere Italiano |
| 100 | Enzo Maolucci | 318 | 1946 | vivente | https://it.wikipedia.org/wiki/Enzo_Maolucci | |
| 140 | Kurt Weill | 312 | 1900 | 1950 | https://it.wikipedia.org/wiki/Kurt_Weill | |
| 330 | Pierre Degeyter | 311 | 1848 | 1932 | https://it.wikipedia.org/wiki/Pierre_Degeyter | Su it.wikipedia il lemma compare come "Pierre Degeyter" (variante di "De Geyter") |
| 123 | Luigi Tenco | 307 | 1938 | 1967 | https://it.wikipedia.org/wiki/Luigi_Tenco | |
| 105 | Peppino Mereu | 305 | 1872 | 1901 | https://it.wikipedia.org/wiki/Peppino_Mereu | |
| 252 | Rafael Alberti | 301 | 1902 | 1999 | https://it.wikipedia.org/wiki/Rafael_Alberti | |
| 54 | Mario Pogliotti | 296 | 1927 | 2006 | nessuna | Nessuna voce Wikipedia dedicata; dati confermati da più fonti concordanti (ilDeposito.org, Wikitesti, Discogs) |
| 10 | Mimmo Boninelli | 292 | 1951 | 2016 | nessuna | Nessuna voce Wikipedia dedicata; dati da Istituto Ernesto de Martino e Biblioteca civica di Bergamo |
| 245 | Claudio Bernieri | 289 | non trovato | non trovato | nessuna | Nato a Milano, anno non reperibile; non chiaro se vivente |
| 81 | Luigi Molinari | 281 | 1866 | 1918 | https://it.wikipedia.org/wiki/Luigi_Molinari | |

## Riepilogo batch 2

- 39/40 autori processati con dati (1, Luciano Rossi, lasciato in sospeso per l'anomalia sopra)
- 33 con pagina Wikipedia trovata (1 in tedesco, 1 in inglese), 5 senza pagina dedicata (Paola Nicolazzi, Pardo Fornaciari, Anna Barile, Mario Pogliotti, Mimmo Boninelli)
- 1 anno_nascita non trovato: Claudio Bernieri
- Da correggere in Drupal: "Leo Ferrè" → "Léo Ferré" (refuso già presente nel nodo)

## Prossimi passi

1. Valida/correggi le due tabelle (in particolare righe con ⚠️/🚨 e quelle senza Wikipedia)
2. Chiarire con la redazione il caso Luciano Rossi (nid 170) prima di procedere
3. Conferma e scrivo i dati validati in Drupal (locale, DDEV)
4. Se ok, si procede con il batch successivo di autori (81°-...)
