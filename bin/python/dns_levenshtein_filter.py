#!/usr/bin/env python3
import argparse
import json
import os
import re
from functools import lru_cache
from pathlib import Path


LEET_TRANSLATION = str.maketrans({
    "0": "o",
    "1": "l",
    "3": "e",
    "4": "a",
    "5": "s",
    "6": "g",
    "7": "t",
    "8": "b",
    "9": "g",
    "@": "a",
    "$": "s",
})

COMMON_PREFIXES = ("www", "m", "mobile")


def normalize_domain(domain: str) -> str:
    value = (domain or "").strip().lower().strip(".")
    if not value:
        return ""

    value = value.replace("_", "-")
    labels = [p for p in value.split(".") if p]
    decoded = []
    for label in labels:
        try:
            if label.startswith("xn--"):
                label = label.encode("ascii").decode("idna")
        except Exception:
            pass
        safe = re.sub(r"[^a-z0-9-]", "", label.lower())
        safe = re.sub(r"-+", "-", safe).strip("-")
        if safe:
            decoded.append(safe)

    while len(decoded) > 2 and decoded[0] in COMMON_PREFIXES:
        decoded.pop(0)

    return ".".join(decoded)


def to_sld_tld(domain: str) -> str:
    parts = [p for p in domain.split(".") if p]
    if len(parts) >= 2:
        return "{}.{}".format(parts[-2], parts[-1])
    return domain


def de_leet(value: str) -> str:
    return value.translate(LEET_TRANSLATION)


def split_core(core: str):
    parts = [p for p in core.split(".") if p]
    if len(parts) >= 2:
        return parts[-2], parts[-1]
    if len(parts) == 1:
        return parts[0], ""
    return "", ""


def max_distance_for(word_len: int) -> int:
    if word_len <= 5:
        return 1
    if word_len <= 12:
        return 2
    return 3


def max_distance_known_for(word_len: int) -> int:
    if word_len <= 12:
        return 1
    return 2


def bounded_levenshtein(a: str, b: str, max_dist: int) -> int:
    if a == b:
        return 0
    if abs(len(a) - len(b)) > max_dist:
        return max_dist + 1

    if len(a) > len(b):
        a, b = b, a

    prev = list(range(len(b) + 1))
    for i, ca in enumerate(a, start=1):
        cur = [i] + [0] * len(b)
        row_min = cur[0]
        for j, cb in enumerate(b, start=1):
            ins = cur[j - 1] + 1
            delete = prev[j] + 1
            replace = prev[j - 1] + (0 if ca == cb else 1)
            cur[j] = min(ins, delete, replace)
            if cur[j] < row_min:
                row_min = cur[j]

        if row_min > max_dist:
            return max_dist + 1
        prev = cur

    return prev[-1]


def load_list(path: Path):
    if not path.exists():
        return set()

    values = set()
    with path.open("r", encoding="utf-8") as handle:
        for raw in handle:
            line = raw.strip()
            if not line or line.startswith("#"):
                continue
            normalized = normalize_domain(line)
            if normalized:
                values.add(to_sld_tld(normalized))
    return values


def resolve_path(path_value: str, fallback_names):
    p = Path(path_value)
    if p.exists():
        return p

    script_dir = Path(os.path.dirname(os.path.abspath(__file__)))
    search_paths = [Path.cwd(), script_dir]
    for base in search_paths:
        for name in fallback_names:
            candidate = base / name
            if candidate.exists():
                return candidate

    return p


class MatchResult:
    def __init__(self, blocked, reason, matched, distance, checked):
        self.blocked = blocked
        self.reason = reason
        self.matched = matched
        self.distance = distance
        self.checked = checked

    def to_json(self) -> str:
        return json.dumps(
            {
                "blocked": self.blocked,
                "reason": self.reason,
                "matched": self.matched,
                "distance": self.distance,
                "checked": self.checked,
            },
            ensure_ascii=False,
        )


class FilterEngine:
    def __init__(self, blacklist, known):
        self.blacklist = blacklist
        self.known = known

        self.black_by_len = {}
        self.known_by_len = {}
        for item in blacklist:
            self.black_by_len.setdefault(len(item), []).append(item)
        for item in known:
            self.known_by_len.setdefault(len(item), []).append(item)

    def _candidates(self, source, size, max_dist):
        items = []
        for length in range(size - max_dist, size + max_dist + 1):
            items.extend(source.get(length, []))
        return items

    @lru_cache(maxsize=8192)
    def check(self, input_domain: str) -> MatchResult:
        normalized = normalize_domain(input_domain)
        core = to_sld_tld(normalized)
        de_leet_core = to_sld_tld(de_leet(core))
        core_label, core_tld = split_core(core)
        de_leet_label, _ = split_core(de_leet_core)

        if not core:
            return MatchResult(False, "empty", "", -1, core)

        # Whitelist precedence: an explicitly known domain is always allowed.
        if core in self.known:
            return MatchResult(False, "known_exact", core, 0, core)

        if core in self.blacklist or de_leet_core in self.blacklist:
            matched = core if core in self.blacklist else de_leet_core
            return MatchResult(True, "blacklist_exact", matched, 0, core)

        if de_leet_core in self.known and core != de_leet_core:
            return MatchResult(True, "leet_typosquat_known_domain", de_leet_core, 1, core)

        max_dist = max_distance_for(len(de_leet_core))

        for candidate in self._candidates(self.black_by_len, len(de_leet_core), max_dist):
            distance = bounded_levenshtein(de_leet_core, candidate, max_dist)
            if distance <= max_dist:
                return MatchResult(True, "blacklist_fuzzy", candidate, distance, core)

        known_max_dist = max_distance_known_for(len(de_leet_core))
        for candidate in self._candidates(self.known_by_len, len(de_leet_core), known_max_dist):
            if candidate == core or candidate == de_leet_core:
                continue
            cand_label, cand_tld = split_core(candidate)
            if core_tld and cand_tld and core_tld != cand_tld:
                continue
            distance = bounded_levenshtein(de_leet_label or de_leet_core, cand_label or candidate, known_max_dist)
            if distance <= known_max_dist:
                return MatchResult(True, "typosquat_known_domain", candidate, distance, core)

        return MatchResult(False, "allow", "", -1, core)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="DNS fuzzy filter (normalization + leet + Levenshtein).")
    parser.add_argument("--domain", required=True, help="Domaine à vérifier")
    parser.add_argument("--blacklist", default="/etc/mbox/dns/blacklist.txt", help="Chemin blacklist")
    parser.add_argument("--known", default="/etc/mbox/dns/knowdomain.txt", help="Chemin domaines connus")
    parser.add_argument("--json", action="store_true", help="Sortie JSON")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    blacklist_path = resolve_path(args.blacklist, ["blacklist.txt"])
    known_path = resolve_path(args.known, ["knowdomain.txt"])

    blacklist = load_list(blacklist_path)
    known = load_list(known_path)
    engine = FilterEngine(blacklist=blacklist, known=known)
    result = engine.check(args.domain)

    if args.json:
        print(result.to_json())
    else:
        verdict = "BLOCK" if result.blocked else "ALLOW"
        print("{} | reason={} | checked={} | matched={} | distance={}".format(
            verdict,
            result.reason,
            result.checked,
            result.matched,
            result.distance,
        ))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
