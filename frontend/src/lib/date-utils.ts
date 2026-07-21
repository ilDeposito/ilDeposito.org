const ROME_TZ = 'Europe/Rome';

/**
 * Giorno/mese/anno "oggi" nel fuso di Roma (gestisce CET/CEST). Roma è
 * avanti su UTC, quindi tra mezzanotte e ~2:00 ora di Roma l'istante
 * corrente è ancora nel giorno UTC precedente: usare getUTCDate/getUTCMonth
 * su `new Date()` per calcolare "oggi" in quella finestra dà il giorno
 * sbagliato (es. build notturna che prende l'evento del giorno prima).
 */
export function oggiRoma(date: Date = new Date()): { day: number; month: number; year: number } {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: ROME_TZ,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).formatToParts(date);
  return {
    day: Number(parts.find((p) => p.type === 'day')!.value),
    month: Number(parts.find((p) => p.type === 'month')!.value),
    year: Number(parts.find((p) => p.type === 'year')!.value),
  };
}
