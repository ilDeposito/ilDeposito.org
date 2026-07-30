import type { CantoDetail } from './api/types.js';

export interface RelatedCanto {
  slug: string;
  titolo: string;
  videoUrl: string | null;
  accordi: string | null;
  autori: string;
}

const RELATED_PAGE_SIZE = 5;
const RELATED_MAX_SAME_AUTHOR = 3;
const RELATED_MIN_NON_SAME_AUTHOR = 2;
const WEIGHT_AUTORI = 5;
const WEIGHT_PERIODO = 3;
const WEIGHT_TAGS = 2;
const HUB_PENALTY_K = 1.2;

interface CantoFeatures {
  autori: Set<string>;
  tags: Set<string>;
  periodo: string | null;
}

type ScoredEntry = {
  candidate: CantoDetail;
  scoreBase: number;
  scoreFinal: number;
  sameAuthor: boolean;
  tieBreak: number;
};

const hubDegreeCache = new WeakMap<CantoDetail[], Map<string, number>>();

export function getUniqueAutori(canto: CantoDetail) {
  return [...canto.autoriTesto, ...canto.autoriMusica].filter(
    (autore, index, arr) => arr.findIndex((a) => a.slug === autore.slug) === index
  );
}

function intersects(setA: Set<string>, values: string[]): boolean {
  return values.some((value) => setA.has(value));
}

function getFeatures(canto: CantoDetail): CantoFeatures {
  return {
    autori: new Set(getUniqueAutori(canto).map((a) => a.slug)),
    tags: new Set(canto.tags.map((t) => t.slug)),
    periodo: canto.periodi[0]?.slug ?? null,
  };
}

function getBaseScore(source: CantoFeatures, candidate: CantoFeatures): { score: number; sameAuthor: boolean } {
  const sameAuthor = intersects(source.autori, [...candidate.autori]);
  const samePeriodo = source.periodo != null && candidate.periodo === source.periodo;
  const sameTag = intersects(source.tags, [...candidate.tags]);

  let score = 0;
  if (sameAuthor) score += WEIGHT_AUTORI;
  if (samePeriodo) score += WEIGHT_PERIODO;
  if (sameTag) score += WEIGHT_TAGS;

  return { score, sameAuthor };
}

function stableHash(input: string): number {
  let hash = 2166136261;
  for (let i = 0; i < input.length; i += 1) {
    hash ^= input.charCodeAt(i);
    hash = Math.imul(hash, 16777619);
  }
  return hash >>> 0;
}

function getHubDegreeMap(allCanti: CantoDetail[]): Map<string, number> {
  const cached = hubDegreeCache.get(allCanti);
  if (cached) return cached;

  const features = allCanti.map((canto) => ({ canto, features: getFeatures(canto) }));
  const degreeMap = new Map<string, number>();
  for (const { canto } of features) degreeMap.set(canto.slug, 0);

  for (let i = 0; i < features.length; i += 1) {
    for (let j = i + 1; j < features.length; j += 1) {
      const a = features[i];
      const b = features[j];
      const { score } = getBaseScore(a.features, b.features);
      if (score <= 0) continue;
      degreeMap.set(a.canto.slug, (degreeMap.get(a.canto.slug) ?? 0) + 1);
      degreeMap.set(b.canto.slug, (degreeMap.get(b.canto.slug) ?? 0) + 1);
    }
  }

  hubDegreeCache.set(allCanti, degreeMap);
  return degreeMap;
}

export function buildRelatedForCanto(source: CantoDetail, allCanti: CantoDetail[]): RelatedCanto[] {
  const sourceFeatures = getFeatures(source);
  const degreeMap = getHubDegreeMap(allCanti);

  const scored: ScoredEntry[] = allCanti
    .filter((candidate) => candidate.slug !== source.slug)
    .map((candidate) => {
      const candidateFeatures = getFeatures(candidate);
      const { score, sameAuthor } = getBaseScore(sourceFeatures, candidateFeatures);
      const degree = degreeMap.get(candidate.slug) ?? 0;
      const hubPenalty = HUB_PENALTY_K * Math.log1p(degree);

      return {
        candidate,
        scoreBase: score,
        scoreFinal: score - hubPenalty,
        sameAuthor,
        tieBreak: stableHash(`${source.slug}|${candidate.slug}`),
      };
    })
    .filter((entry) => entry.scoreBase > 0)
    .sort((a, b) => {
      if (b.scoreFinal !== a.scoreFinal) return b.scoreFinal - a.scoreFinal;
      if (b.scoreBase !== a.scoreBase) return b.scoreBase - a.scoreBase;
      if (a.tieBreak !== b.tieBreak) return a.tieBreak - b.tieBreak;
      return a.candidate.titolo.localeCompare(b.candidate.titolo, 'it');
    });

  const nonSameAuthorPool = scored.filter((entry) => !entry.sameAuthor);
  const minNonSameAuthor = Math.min(
    RELATED_MIN_NON_SAME_AUTHOR,
    RELATED_PAGE_SIZE,
    nonSameAuthorPool.length
  );

  const selected: ScoredEntry[] = nonSameAuthorPool.slice(0, minNonSameAuthor);
  const selectedSlugs = new Set(selected.map((entry) => entry.candidate.slug));
  const skippedSameAuthor: ScoredEntry[] = [];
  let sameAuthorCount = 0;

  for (const entry of scored) {
    if (selectedSlugs.has(entry.candidate.slug)) continue;
    if (entry.sameAuthor && sameAuthorCount >= RELATED_MAX_SAME_AUTHOR) {
      skippedSameAuthor.push(entry);
      continue;
    }
    selected.push(entry);
    selectedSlugs.add(entry.candidate.slug);
    if (entry.sameAuthor) sameAuthorCount += 1;
    if (selected.length >= RELATED_PAGE_SIZE) break;
  }

  if (selected.length < RELATED_PAGE_SIZE) {
    for (const entry of skippedSameAuthor) {
      if (selectedSlugs.has(entry.candidate.slug)) continue;
      selected.push(entry);
      selectedSlugs.add(entry.candidate.slug);
      if (selected.length >= RELATED_PAGE_SIZE) break;
    }
  }

  return selected.map(({ candidate }) => ({
    slug: candidate.slug,
    titolo: candidate.titolo,
    videoUrl: candidate.videoUrl,
    accordi: candidate.accordi,
    autori: getUniqueAutori(candidate).map((a) => a.titolo).join(', '),
  }));
}
