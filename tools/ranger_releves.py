#!/usr/bin/env python3
"""Range les relevés bancaires (PDF / CSV La Banque Postale) dans data/.

Usage :
    python3 -I tools/ranger_releves.py [SOURCE ...]            # dry-run (par défaut)
    python3 -I tools/ranger_releves.py --apply [SOURCE ...]    # exécute les déplacements

Sans SOURCE : data/inbox/ uniquement (jamais la racine du projet, qui contient le code).

Arborescence produite :
    data/releves/<alias>/<AAAA>/<AAAA-MM>_releve.pdf      relevés mensuels (mois = date du nouveau solde)
    data/releves/<alias>/frais/<AAAA>_frais-annuels.pdf   relevés annuels de frais
    data/releves/<alias>/csv/<AAAA-MM-JJ>_export.csv      exports CSV (date d'export de l'en-tête)
    data/divers/                                          autres fichiers (ods, …)
    data/_doublons/                                       fichiers identiques à un fichier déjà rangé
    data/_a_verifier/                                     fichiers non reconnus ou conflits de nom

Alias des comptes : data/comptes.ini (section [comptes], "<numéro sans espaces> = <alias>").
Compte inconnu => alias provisoire "compte-<4 derniers caractères>" + ajout au fichier.
Rien n'est jamais supprimé ; chaque déplacement est journalisé dans data/_rangement.log (TSV).
Les numéros de compte ne sont jamais affichés en clair.
"""
from __future__ import annotations

import argparse
import configparser
import datetime as dt
import hashlib
import re
import shutil
import subprocess
import sys
import zipfile
from dataclasses import dataclass
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / "data"
CONFIG = DATA / "comptes.ini"
LOG = DATA / "_rangement.log"

RE_PDF_ACCOUNT = re.compile(r"Situation de votre (?:CCP|compte)[^\n]*?n°\s*([0-9][0-9 ]*[A-Z]\s?[0-9]{3})", re.I)
RE_PDF_ACCOUNT_FALLBACK = re.compile(r"(?:CCP|Compte Courant Postal)[^\n]*?n°\s*([0-9]{2} ?[0-9]{3} ?[0-9]{2} ?[A-Z] ?[0-9]{3})", re.I)
RE_PDF_CLOSING = re.compile(r"nouveau solde au\s*([0-9]{2})/([0-9]{2})/([0-9]{4})", re.I)
RE_PDF_IBAN = re.compile(r"IBAN\s*:\s*(FR[0-9A-Z ]{23,32})", re.I)
# Relevé annuel de frais : "Décompte des frais" + "Période Du jj/mm/aaaa au jj/mm/aaaa"
RE_PDF_FEES = re.compile(r"Période\s+Du\s+[0-9/]{10}\s+au\s+[0-9]{2}/[0-9]{2}/([0-9]{4})", re.I)
RE_CSV_ACCOUNT = re.compile(r"^Num.ro Compte\s*;\s*([0-9A-Z]+)", re.I | re.M)
RE_CSV_DATE = re.compile(r"^Date\s*;\s*([0-9]{2})/([0-9]{2})/([0-9]{4})", re.M)


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def file_hash(path: Path) -> str:
    return sha256(path.read_bytes())


def mask(account: str) -> str:
    return "****" + account[-4:]


def pdf_text(path: Path, first_page_only: bool = True) -> str:
    cmd = ["pdftotext", "-q", "-layout"]
    if first_page_only:
        cmd += ["-l", "1"]
    cmd += [str(path), "-"]
    res = subprocess.run(cmd, capture_output=True, text=True, errors="replace")
    return res.stdout


@dataclass
class Plan:
    src: Path
    dest: Path
    reason: str


class AccountBook:
    def __init__(self, path: Path):
        self.path = path
        self.cfg = configparser.ConfigParser()
        self.cfg.optionxform = str  # garder la casse (R majuscule)
        if path.exists():
            self.cfg.read(path, encoding="utf-8")
        if not self.cfg.has_section("comptes"):
            self.cfg.add_section("comptes")
        self.new: dict[str, str] = {}

    def alias(self, account: str) -> str:
        if self.cfg.has_option("comptes", account):
            return self.cfg.get("comptes", account)
        if account not in self.new:
            self.new[account] = f"compte-{account[-4:].lower()}"
        return self.new[account]

    def save(self) -> None:
        if not self.new:
            return
        for acc, al in self.new.items():
            self.cfg.set("comptes", acc, al)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        with self.path.open("w", encoding="utf-8") as fh:
            fh.write("# Numéro de compte (sans espaces) = alias utilisé dans data/releves/\n")
            self.cfg.write(fh)


def classify(path: Path, book: AccountBook) -> tuple[Path | None, str]:
    """Retourne (destination relative à data/, description). None => _a_verifier."""
    head = path.read_bytes()[:5]
    name = path.name.lower()

    if head.startswith(b"%PDF"):
        text = pdf_text(path)
        fees = None
        c = RE_PDF_CLOSING.search(text)
        if not c:
            # Relevés anciens (2016-2018) : le nouveau solde n'est qu'en page 2 ou suivantes.
            full = pdf_text(path, first_page_only=False)
            c = RE_PDF_CLOSING.search(full)
            # Relevé annuel de frais seul : pas de nouveau solde (un relevé mensuel peut l'avoir en annexe).
            if not c and "compte des frais" in full:
                fees = RE_PDF_FEES.search(full)
        m = RE_PDF_ACCOUNT.search(text) or RE_PDF_ACCOUNT_FALLBACK.search(text)
        if m:
            account = re.sub(r"\s", "", m.group(1)).upper()
        else:
            # IBAN FR : FRkk + banque(5) + guichet(5) + compte(11) + clé(2)
            iban = RE_PDF_IBAN.search(text)
            if not iban:
                return None, "PDF : compte introuvable"
            account = re.sub(r"\s", "", iban.group(1)).upper()[14:25]
        alias = book.alias(account)
        if fees:
            return Path("releves", alias, "frais", f"{fees.group(1)}_frais-annuels.pdf"), f"frais annuels {fees.group(1)} [{alias} {mask(account)}]"
        if not c:
            return None, f"PDF : date de relevé introuvable [{alias}]"
        d, mth, y = c.groups()
        return Path("releves", alias, y, f"{y}-{mth}_releve.pdf"), f"relevé {y}-{mth} [{alias} {mask(account)}]"

    if name.endswith(".csv"):
        text = path.read_bytes()[:2000].decode("latin-1")
        a = RE_CSV_ACCOUNT.search(text)
        d = RE_CSV_DATE.search(text)
        if not a or not d:
            return None, "CSV : en-tête La Banque Postale non reconnu"
        account = a.group(1).upper()
        alias = book.alias(account)
        dd, mm, yy = d.groups()
        return Path("releves", alias, "csv", f"{yy}-{mm}-{dd}_export.csv"), f"export CSV {yy}-{mm}-{dd} [{alias} {mask(account)}]"

    if name.endswith(".zip"):
        return Path("_zip"), "archive zip"  # traité à part

    return Path("divers", path.name), "autre fichier"


def collect_sources(args_sources: list[str]) -> list[Path]:
    files: list[Path] = []
    if args_sources:
        roots = [Path(s).resolve() for s in args_sources]
    else:
        inbox = DATA / "inbox"
        roots = [inbox] if inbox.is_dir() else []
    for r in roots:
        if r.is_file():
            files.append(r)
        elif r.is_dir():
            files.extend(p for p in sorted(r.rglob("*")) if p.is_file())
    return files


def existing_hashes() -> dict[str, Path]:
    known: dict[str, Path] = {}
    for sub in ("releves", "divers"):
        base = DATA / sub
        if base.is_dir():
            for p in base.rglob("*"):
                if p.is_file():
                    known.setdefault(file_hash(p), p)
    return known


def same_pdf_content(a: Path, b: Path) -> bool:
    """Même relevé re-téléchargé (octets différents, texte identique)."""
    norm = lambda p: re.sub(r"\s+", " ", pdf_text(p, first_page_only=False)).strip()
    return a.suffix.lower() != ".csv" and b.suffix.lower() != ".csv" and norm(a) == norm(b)


def unique_path(p: Path, taken: set[Path]) -> Path:
    if not p.exists() and p not in taken:
        return p
    i = 2
    while True:
        cand = p.with_name(f"{p.stem}__{i}{p.suffix}")
        if not cand.exists() and cand not in taken:
            return cand
        i += 1


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("sources", nargs="*", help="fichiers ou dossiers à ranger")
    ap.add_argument("--apply", action="store_true", help="exécuter les déplacements (sinon dry-run)")
    args = ap.parse_args()

    if shutil.which("pdftotext") is None:
        print("ERREUR : pdftotext (poppler-utils) introuvable", file=sys.stderr)
        return 1

    book = AccountBook(CONFIG)
    known = existing_hashes()           # hash -> fichier déjà rangé
    planned: dict[str, Path] = {}       # hash -> destination prévue dans ce run
    dest_src: dict[Path, Path] = {}     # destination prévue -> fichier source
    taken: set[Path] = set()
    plans: list[Plan] = []
    zips: list[Path] = []

    files = collect_sources(args.sources)
    for f in files:
        dest_rel, desc = classify(f, book)
        if dest_rel == Path("_zip"):
            zips.append(f)
            continue
        h = file_hash(f)
        if h in known or h in planned:
            twin = known.get(h) or planned[h]
            dest = unique_path(DATA / "_doublons" / f.name, taken)
            plans.append(Plan(f, dest, f"doublon de {twin.relative_to(DATA)}"))
        elif dest_rel is None:
            dest = unique_path(DATA / "_a_verifier" / f.name, taken)
            plans.append(Plan(f, dest, desc))
        else:
            target = DATA / dest_rel
            occupant = target if target.exists() else dest_src.get(target)
            if occupant is not None and same_pdf_content(f, occupant):
                dest = unique_path(DATA / "_doublons" / f.name, taken)
                plans.append(Plan(f, dest, f"même contenu que {dest_rel} (re-téléchargement)"))
            elif target.exists() or target in taken:
                dest = unique_path(DATA / "_a_verifier" / dest_rel.name, taken)
                plans.append(Plan(f, dest, f"CONFLIT : {dest_rel} existe déjà avec un contenu différent ({desc})"))
            else:
                dest = target
                plans.append(Plan(f, dest, desc))
                dest_src[dest] = f
            planned[h] = dest
        taken.add(plans[-1].dest)

    # Archives zip : doublon si tout son contenu est déjà rangé / en cours de rangement
    all_hashes = set(known) | set(planned)
    for z in zips:
        with zipfile.ZipFile(z) as zf:
            members = [i for i in zf.infolist() if not i.is_dir()]
            missing = [i.filename for i in members if sha256(zf.read(i)) not in all_hashes]
        if missing:
            dest = unique_path(DATA / "_a_verifier" / z.name, taken)
            plans.append(Plan(z, dest, f"zip : {len(missing)}/{len(members)} fichiers absents ailleurs — à extraire dans data/inbox/"))
        else:
            dest = unique_path(DATA / "_doublons" / z.name, taken)
            plans.append(Plan(z, dest, f"zip : ses {len(members)} fichiers sont déjà présents"))
        taken.add(dest)

    # --- Rapport
    def bucket(p: Plan) -> str:
        rel = p.dest.relative_to(DATA)
        return rel.parts[0] if rel.parts[0] != "releves" else "/".join(rel.parts[:2])

    print(f"{'APPLY' if args.apply else 'DRY-RUN'} — {len(plans)} fichier(s) analysé(s)\n")
    for p in sorted(plans, key=lambda p: str(p.dest)):
        print(f"  {str(p.src.relative_to(ROOT)) if p.src.is_relative_to(ROOT) else p.src}\n    -> {p.dest.relative_to(DATA)}   ({p.reason})")
    counts: dict[str, int] = {}
    for p in plans:
        counts[bucket(p)] = counts.get(bucket(p), 0) + 1
    print("\nRésumé :")
    for k, v in sorted(counts.items()):
        print(f"  {k:<30} {v}")
    if book.new:
        print("\nNouveaux comptes (alias provisoires, à renommer dans data/comptes.ini) :")
        for acc, al in book.new.items():
            print(f"  {mask(acc)} -> {al}")

    if not args.apply:
        print("\nDry-run : rien n'a été déplacé. Relancer avec --apply pour exécuter.")
        return 0

    # --- Exécution
    DATA.mkdir(exist_ok=True)
    book.save()
    stamp = dt.datetime.now().isoformat(timespec="seconds")
    with LOG.open("a", encoding="utf-8") as log:
        for p in plans:
            p.dest.parent.mkdir(parents=True, exist_ok=True)
            if p.dest.exists():
                raise SystemExit(f"Destination déjà présente, arrêt : {p.dest}")
            shutil.move(str(p.src), str(p.dest))
            log.write(f"{stamp}\t{p.src}\t{p.dest}\t{p.reason}\n")
    # suppression des dossiers sources devenus vides (jamais data/)
    for f in files:
        d = f.parent
        while d != ROOT and d != DATA and d.is_dir() and not any(d.iterdir()) and DATA not in d.parents:
            d.rmdir()
            d = d.parent
    print(f"\n{len(plans)} fichier(s) déplacé(s). Journal : {LOG.relative_to(ROOT)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
