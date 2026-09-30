# Repozytorium i aktualizacje Decka Bilety

Repozytorium: https://github.com/kaulpl/decka-ticket

Kod przeznaczony do repozytorium kaulpl/decka-ticket. Stan wykonania publikacji potwierdzają GitHub Actions i zakładka Releases.

## Budowanie

Wymagane Node.js 22, pnpm 10, Python 3.9+; do testów PHP 8.2+ z pdo_sqlite i mbstring.

```sh
pnpm --dir frontend --ignore-workspace install --frozen-lockfile
pnpm --dir frontend run build
php tests/domain.php
php tests/integration.php
php tests/updater.php
python3 scripts/package.py
```

Instalator: `dist/decka-bilety-0.2.0.zip`. ZIP zawiera katalog `decka-bilety`, gotowy frontend Next.js i biblioteki PDF. ZIP źródeł służy programiście, nie instalacji WordPressa. Hosting WordPressa nie potrzebuje serwera Node.js.

## Publikowanie kolejnych zmian

1. Pracujemy w osobnej gałęzi i otwieramy PR do `main`.
2. PR uruchamia testy i budowanie paczki, ale nie publikuje aktualizacji.
3. Po połączeniu PR-a do `main` workflow ponownie buduje i testuje kod.
4. Numer wydania jest wyliczany automatycznie: jeśli numer źródeł nie jest nowszy od tagów, zwiększa się ostatni człon (np. 0.2.0 → 0.2.1). Numer jest zapisywany w budowanej paczce i manifeście; nie tworzy dodatkowego commita na `main`.
5. Po udanych testach powstaje szkic wydania, przesyłane są wszystkie pliki, a dopiero potem wydanie jest publikowane. Powtórzenie workflow nie nadpisuje już opublikowanego wydania.
6. WordPress wykrywa gotową aktualizację standardowym harmonogramem lub przyciskiem „Sprawdź aktualizacje teraz”. Można ją zainstalować ze strony Wtyczki. Administrator może również włączyć standardowe automatyczne aktualizacje WordPressa dla Decka Bilety.

Sam PR ani nieudane testy nie publikują wydania. Repozytorium musi mieć włączone GitHub Actions; job publikowania używa `contents: write`. Pierwszą instalację wykonuje się ręcznie z instalatora ZIP, kolejne wersje obsługuje updater. Numer źródeł można podnieść ręcznie przy większym wydaniu.

Updater czyta wyłącznie najnowsze stabilne GitHub Release. Do wydania muszą być dołączone:

- `decka-bilety-X.Y.Z.zip` — instalator wygenerowany skryptem;
- `decka-bilety-update.json` — wersja, slug, nazwa paczki, SHA-256, wymagania WordPress/PHP.

Nie wystarczy commit na `main`, tag bez wydania ani automatyczny „Source code (zip)” GitHuba. Repozytorium musi pozostać publiczne; prywatne repo wymaga osobnej obsługi autoryzacji.

## Przycisk w ustawieniach

„Sprawdź aktualizacje teraz” wymaga uprawnień zarządzania Decką i `update_plugins`. Usuwa cache metadanych wtyczek WordPressa oraz cache wydania Decki, pyta GitHub i odświeża systemowy wykaz aktualizacji. Nie czyści cache całej strony i nie instaluje kodu. Instalację uruchamia administrator na standardowej stronie Wtyczki.

Brak wydania, limit GitHuba i błąd połączenia mają osobne komunikaty. Pobierana paczka musi odpowiadać manifestowi; SHA-256 jest sprawdzane przed przekazaniem do instalatora WordPressa. To weryfikacja integralności, nie niezależny podpis autora.

Przed pierwszą aktualizacją na produkcji sprawdzić na kopii strony cały przebieg instalacji z prawdziwego wydania oraz wykonać kopię bazy i plików. W obecnym pustym repozytorium nie można jeszcze przetestować rzeczywistego pobrania opublikowanego instalatora.

Dokumentacja: [WordPress cache wtyczek](https://developer.wordpress.org/reference/functions/wp_clean_plugins_cache/), [sprawdzanie aktualizacji](https://developer.wordpress.org/reference/functions/wp_update_plugins/), [GitHub Releases API](https://docs.github.com/en/rest/releases/releases#get-the-latest-release).


## Test panelu w przeglądarce

Testy w `tests/` korzystają wyłącznie z lokalnych kont i danych `example.test`. Nie uruchamiać ich na stronie produkcyjnej. Atrapa poczty w `tests/mu` przechwytuje wysyłkę i nie wchodzi do paczki instalacyjnej.

```sh
pnpm install --frozen-lockfile
pnpm exec playwright install chromium
pnpm exec wp-playground-cli server --port=9411 --mount=./plugin:/wordpress/wp-content/plugins/decka-bilety --mount=./tests/mu:/wordpress/wp-content/mu-plugins --blueprint=tests/blueprint.json --define-bool DISABLE_WP_CRON true --workers=1
```

W drugim terminalu: `WP_URL=http://127.0.0.1:9411 node tests/admin.mjs`. Test administratora uruchamiać na świeżej instancji z blueprintu. Opcjonalnie `CHROME_PATH` wskazuje lokalny Chrome, a `DECKA_PLAYWRIGHT_MODULE` zewnętrzną instalację Playwright. Testy nie wymagają ścieżek do komputera autora.
