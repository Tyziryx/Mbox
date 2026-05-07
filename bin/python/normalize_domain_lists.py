#!/usr/bin/env python3
import argparse
import json
import re
from pathlib import Path

COMMON_PREFIXES = ("www", "m", "mobile")


def normalize_domain(domain):
    value = (domain or "").strip().lower().strip(".")
    if not value:
        return ""

    value = value.replace("_", "-")
    labels = [p for p in value.split(".") if p]
    cleaned = []

    for label in labels:
        label = re.sub(r"[^a-z0-9-]", "", label)
        label = re.sub(r"-+", "-", label).strip("-")
        if label:
            cleaned.append(label)

    while len(cleaned) > 2 and cleaned[0] in COMMON_PREFIXES:
        cleaned.pop(0)

    return ".".join(cleaned)


def to_sld_tld(domain):
    parts = [p for p in domain.split(".") if p]
    if len(parts) >= 2:
        return "{}.{}".format(parts[-2], parts[-1])
    return domain


SECTION_RE = re.compile(r"^##\s*(\S+)\s*##$")


def read_domain_list(path):
    """
    Retourne (comments, sections) ou sections est une liste ordonnee
    de (section_name_or_None, [domaines]). La premiere section (None)
    contient les domaines hors de toute balise `## CATEGORY ##`.
    """
    comments = []
    sections = [(None, [])]
    if not path.exists():
        return comments, sections

    with path.open("r", encoding="utf-8") as handle:
        for raw in handle:
            line = raw.strip()
            if not line:
                continue

            m = SECTION_RE.match(line)
            if m:
                sections.append((m.group(1).upper(), []))
                continue

            if line.startswith("#"):
                comments.append(line)
                continue

            normalized = normalize_domain(line)
            if not normalized:
                continue
            sections[-1][1].append(to_sld_tld(normalized))

    dedup = []
    for name, vals in sections:
        dedup.append((name, sorted(set(vals))))
    return comments, dedup


def flatten_sections(sections):
    out = []
    for _, vals in sections:
        out.extend(vals)
    return sorted(set(out))


def exclude_domains_from_sections(sections, excluded):
    filtered = []
    excluded_set = set(excluded)
    for name, vals in sections:
        kept = [v for v in vals if v not in excluded_set]
        filtered.append((name, kept))
    return filtered


def write_domain_list(path, header, sections):
    lines = [header, ""]
    for name, vals in sections:
        if name is not None:
            if lines and lines[-1] != "":
                lines.append("")
            lines.append("## {} ##".format(name))
        lines.extend(vals)
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def parse_args():
    parser = argparse.ArgumentParser(description="Normalize and deduplicate blacklist/known domain files.")
    parser.add_argument("--blacklist", default="blacklist.txt", help="Path to blacklist file")
    parser.add_argument("--known", default="knowdomain.txt", help="Path to known domains file")
    parser.add_argument("--summary", default="domain_lists_summary.json", help="Output summary JSON")
    return parser.parse_args()


def main():
    args = parse_args()

    blacklist_path = Path(args.blacklist)
    known_path = Path(args.known)

    _, blacklist_sections = read_domain_list(blacklist_path)
    _, known_sections = read_domain_list(known_path)

    known = flatten_sections(known_sections)
    blacklist_before = flatten_sections(blacklist_sections)
    removed_from_blacklist_count = len(set(blacklist_before).intersection(set(known)))
    blacklist_sections = exclude_domains_from_sections(blacklist_sections, known)

    write_domain_list(blacklist_path, "# Domaines interdits (normalisés)", blacklist_sections)
    write_domain_list(known_path, "# Domaines légitimes de référence (normalisés)", known_sections)

    blacklist = flatten_sections(blacklist_sections)
    overlap_count = len(set(blacklist).intersection(set(known)))

    summary = {
        "blacklist_file": str(blacklist_path),
        "known_file": str(known_path),
        "blacklist_count": len(blacklist),
        "known_count": len(known),
        "removed_from_blacklist_count": removed_from_blacklist_count,
        "overlap_count": overlap_count,
        "blacklist": blacklist,
        "known": known,
    }

    Path(args.summary).write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    print("OK: normalization completed")
    print("blacklist_count={}".format(len(blacklist)))
    print("known_count={}".format(len(known)))


if __name__ == "__main__":
    main()
