import os
import json
import getpass
import glob
import shutil
from typing import List, Dict, Any, Tuple
import urllib.request
import urllib.error
import base64
# ...existing code...

# Configurazione iniziale
BASE_DOMAIN = "https://www.ildeposito.org"  # Modificare se necessario
USERNAME = "sergej"             # Modificare se necessario
ENDPOINTS: List[str] = [
    "/export/utenti.json",
    "/export/canti.json",
    "/export/autori.json",
    "/export/eventi.json",
    "/export/traduzioni.json",
    "/export/immagini.json",
    "/export/media.json",
    "/export/tags.json",
    "/export/lingue.json",
    "/export/localizzazioni.json",
    "/export/tematiche.json",
    "/export/periodi.json",
    "/export/stats_canti.json",
    "/export/stats_autori.json",
    "/export/stats_eventi.json",
]

FILES_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'web', 'modules', 'custom', 'migrando', 'files')
TIMEOUT = 10  # secondi


def prompt_password() -> str:
    """Richiede la password in modo sicuro senza mostrarla in chiaro."""
    return getpass.getpass("Password: ")


def ensure_dir(path: str) -> None:
    """Crea la directory se non esiste."""
    os.makedirs(path, exist_ok=True)


def fetch_paginated_json(endpoint: str, auth: str, timeout: int) -> Tuple[List[str], List[Dict[str, Any]]]:
    """Scarica tutte le pagine JSON da un endpoint Drupal paginato, salvando in una sottodirectory per endpoint."""
    files_created: List[str] = []
    errors: List[Dict[str, Any]] = []
    page = 0
    base_url = f"{BASE_DOMAIN}{endpoint}"

    filename_base = os.path.splitext(os.path.basename(endpoint))[0]
    # Se il nome inizia con "termini_", rimuovi il prefisso sia per la directory che per il file
    if filename_base.startswith("termini_"):
        clean_name = filename_base[len("termini_"):]
        endpoint_dir = os.path.join(FILES_DIR, clean_name)
        file_prefix = clean_name
    else:
        endpoint_dir = os.path.join(FILES_DIR, filename_base)
        file_prefix = filename_base
    ensure_dir(endpoint_dir)

    import time  # Delay tra richieste
    while True:
        url = f"{base_url}?page={page}"
        req = urllib.request.Request(url, headers={"Authorization": f"Basic {auth}"})
        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                if resp.status != 200:
                    errors.append({
                        "endpoint": endpoint,
                        "page": page,
                        "url": url,
                        "error": f"HTTP {resp.status}"
                    })
                    break
                try:
                    data = json.loads(resp.read().decode("utf-8"))
                except Exception as e:
                    errors.append({
                        "endpoint": endpoint,
                        "page": page,
                        "url": url,
                        "error": f"JSON decode error: {str(e)}"
                    })
                    break
        except urllib.error.HTTPError as e:
            errors.append({
                "endpoint": endpoint,
                "page": page,
                "url": url,
                "error": f"HTTP {e.code}: {e.reason}"
            })
            break
        except urllib.error.URLError as e:
            errors.append({
                "endpoint": endpoint,
                "page": page,
                "url": url,
                "error": f"Request error: {str(e)}"
            })
            break
        if not data:
            # Fine paginazione
            break
        file_path = os.path.join(endpoint_dir, f"{file_prefix}_{page}.json")
        # Se la risposta è una lista, wrappala in un oggetto con chiave 'items'
        if isinstance(data, list):
            data = {"items": data}
        with open(file_path, "w", encoding="utf-8") as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
        files_created.append(file_path)
        page += 1
        time.sleep(3)  # Aspetta 3 secondi tra una richiesta e l'altra
    return files_created, errors


def main() -> None:
    """Funzione principale: gestisce autenticazione, pulizia, download e riepilogo."""
    # Percorso assoluto della directory files nella struttura Drupal
    files_dir = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'web', 'modules', 'custom', 'migrando', 'files')

    # Elimina tutte le subdirectory dentro files
    if os.path.exists(files_dir):
        for entry in os.listdir(files_dir):
            full_path = os.path.join(files_dir, entry)
            if os.path.isdir(full_path):
                try:
                    shutil.rmtree(full_path)
                except Exception as e:
                    print(f"Errore eliminando la directory {full_path}: {e}")
            elif entry.endswith('.json'):
                try:
                    os.remove(full_path)
                except Exception as e:
                    print(f"Errore eliminando il file {full_path}: {e}")

    print(f"Scaricamento JSON da {BASE_DOMAIN} tramite Basic Auth...")
    password = prompt_password()
    auth = base64.b64encode(f"{USERNAME}:{password}".encode()).decode()

    all_files: List[str] = []
    all_errors: List[Dict[str, Any]] = []

    for endpoint in ENDPOINTS:
        print(f"\nEndpoint: {endpoint}")
        files, errors = fetch_paginated_json(endpoint, auth, TIMEOUT)
        for f in files:
            print(f"  ✓ {f}")
        for err in errors:
            print(f"  ⚠️ Errore pagina {err['page']}: {err['error']}")
        all_files.extend(files)
        all_errors.extend(errors)

    print("\n--- Riepilogo ---")
    print(f"Totale file JSON creati: {len(all_files)}")
    if all_files:
        print("Elenco file:")
        for f in all_files:
            print(f"  - {f}")
    if all_errors:
        print("\nErrori riscontrati:")
        for err in all_errors:
            print(f"  - Endpoint: {err['endpoint']} | Pagina: {err['page']} | {err['error']}")
    else:
        print("Nessun errore.")


if __name__ == "__main__":
    main()
