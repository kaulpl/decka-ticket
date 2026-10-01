# Raport testów — Decka Bilety 0.2.0

Data: 30 września 2026 r. Wydanie do odbioru na środowisku testowym.

## Sprawdzone

### Dane i logika — 20 zakończonych powodzeniem sprawdzeń

- Zgodność liczby miejsc z Excelem: B 105, C 130, D 105, razem 340; identyfikatory unikatowe.
- Osiem pól KAMERA poza sprzedażą; zachowane położenie komórek.
- Podział groszy w mini-karnetach i naliczanie rabatów procentowych/kwotowych.
- Odrzucanie błędnej wartości rabatu.
- Podpis Stripe: poprawna treść, zmieniona treść, stary podpis, brak sekretu, rotacja podpisów.
- QR związany z konkretnym biletem i meczem.
- Odczyt 15 domowych meczów z pobranej oficjalnej strony sezonu; unikatowe ID, brak wymyślania niepodanych godzin, przeliczenie Europe/Warsaw na UTC.

### Cykl zamówienia — 32 sprawdzenia zakończone powodzeniem

Uruchomiono rzeczywiste metody PHP wtyczki na adapterze SQLite. Stripe zastąpiono deterministycznym lokalnym modelem odpowiedzi, bez obciążeń.

- Blokada oczekującego miejsca i odrzucenie drugiego zamówienia.
- Powtórzenie żądania zakupu zwraca to samo zamówienie.
- Wycofanie nieudanego zamówienia bez pozostawienia częściowych zapisów.
- Brak biletu dla nieopłaconej sesji, złej kwoty i innego środowiska.
- Ponowiony webhook nie duplikuje biletu.
- Stan szary dopiero po płatności.
- Zły mecz, sfałszowany QR i powtórne wejście są odrzucane.
- Potwierdzone wygaśnięcie płatności zwalnia miejsce.
- Mini-karnet rezerwuje wszystkie mecze albo żadnego; suma kwot pozycji odpowiada cenie pakietu.
- Rabat naliczany po stronie serwera i kontrolowany limit wykorzystania.
- VOUCHER bez Stripe, blokada zajętego nim miejsca.
- Zwrot unieważnia bilety, także gdy zdarzenie zwrotu przyjdzie przed potwierdzeniem płatności.
- Testowe miejsca nie blokują produkcji.
- Generowanie rzeczywistego PDF.

SQLite nie dowodzi poprawności równoległych blokad InnoDB — ten test pozostaje do wykonania na docelowym hostingu.

### WordPress — rzeczywista lokalna instalacja

Uruchomiono odizolowany WordPress w WordPress Playground. Używa on warstwy SQLite; poczta została przechwycona przez testowy moduł i nie była wysyłana.

- Aktywacja wtyczki i utworzenie tabel, ustawień oraz roli biletera.
- Ładowanie interfejsu Next.js przez stronę WordPressa i pobieranie katalogu z REST API.
- Rejestracja kibica przez formularz i poprawny nonce nowej sesji przy pierwszym żądaniu po zalogowaniu.
- Brak skonfigurowanego Stripe zatrzymuje zakup przed zablokowaniem miejsc.
- Konto kibica nie może wystawiać voucherów ani pobierać listy meczów biletera.
- Administrator może wystawić voucher; powtórne zajęcie jego miejsca jest odrzucane.
- Pobranie chronionego PDF przez uprawnione konto.
- Działający panel administratora i eksport CSV.
- Logowanie konta biletera i odmowa dostępu do operacji administratora.
- Działający widok panelu biletera.
- QR odczytany z PDF wystawionego przez WordPress: pierwsze wejście zapisane w panelu biletera, drugi skan odrzucony jako wykorzystany.

### Interfejs i dokument

- Produkcyjny build Next.js i kontrola TypeScript zakończone powodzeniem.
- Kontrola składni wszystkich własnych plików PHP zakończona powodzeniem.
- Automatyczny test przeglądarkowy: wejście w sektory C/B/D i liczby 130/105/105 przycisków, wybór miejsca, cena ulgowa 15 zł, przykład mini-karnetu 65 zł, zerowanie koszyka przy zmianie produktu, zachowanie wyboru na widoku całej hali.
- Tryb demonstracyjny nie przyjmuje płatności.
- Widok mobilny 390 px bez poziomego przewijania całej strony; powiększona mapa ma własne przesuwanie poziome.
- Brak błędów JavaScript w testach interfejsu i WordPressa.
- PDF ma format A4 595,276 × 841,89 pt. Obejrzano render, poprawiono wyrównanie tekstu.
- QR odczytano z wyrenderowanego PDF biblioteką ZXing; odczytany tekst jest dokładnie zgodny z tokenem biletu.
- Fonty są dołączone lokalnie z licencjami OFL.

## Pozostały odbiór na docelowym środowisku

Nie wykonano prawdziwej transakcji Stripe TEST/LIVE ani testu BLIK na koncie klubu, ponieważ nie podano kluczy. Nie zweryfikowano dostarczenia wiadomości przez klubowe SMTP ani dostępu do aparatu na rzeczywistych telefonach bileterów. Nie wykonano testu jednoczesnego kupowania/skanowania na docelowym MySQL/InnoDB, obciążenia meczowego, konfiguracji cache i cron na hostingu.

To ograniczenia zakresu weryfikacji, nie potwierdzenie gotowości do uruchomienia płatnej sprzedaży. Przed produkcją wykonaj kroki odbioru z instrukcji.

## Zakres funkcjonalny wydania

Import terminarza uruchamia się ręcznie. Rzędy 1–8 przyjęto od strony boiska, bo Excel nie zawiera etykiet rzędów. Częściowy zwrot unieważnia całe zamówienie. Statystyki są raportem biletowym, nie księgowym saldem Stripe. Miejsca po zwrocie i niejednoznacznych błędach płatności pozostają zablokowane do wyjaśnienia; brak automatycznego zwalniania na podstawie samego zegara.


## Rozbudowa panelu i GitHub — 0.2.0

- Produkcyjna kompilacja Next.js i kontrola TypeScript: poprawne; statyczne trasy sklepu i administratora.
- Kontrola składni wszystkich 11 własnych plików PHP: poprawna.
- 20 deterministycznych sprawdzeń updatera: brak wydania, nowsza wersja, cache, wymuszone sprawdzenie, zachowanie aktualizacji innych wtyczek, uprawnienia, odrzucenie prerelease/obcej paczki/niezgodnej wersji/brakującego SHA-256, kontrola integralności ZIP, zgodność PHP, błędy sieci i limit API.
- Test rzeczywistego WordPressa: wszystkie 11 podstron, zapis ustawień, nowy mecz i przeliczenie czasu, pakiet dwóch meczów, kod procentowy, blokowanie i zwalnianie miejsca, voucher, chroniony PDF, unieważnienie biletu i CSV.
- Zwalnianie miejsca przypisanego do zamówienia jest odrzucane. Osobny test zakupu potwierdza ochronę ręcznej blokady organizatora (order_id=0).
- Ręczne sprawdzenie prawdziwego GitHuba zwróciło `no_release`: repozytorium nie ma jeszcze publicznego wydania z instalatorem.
- Osobne testy uprawnień: anonimowe żądania do panelu i aktualizacji odrzucone, bileter otrzymuje 403; przełączenie środowiska nie przenosi testowej zajętości do produkcji.
- Wariant mobilny: brak poziomego przewijania całej strony; mapa ma własne przewijanie. Brak błędów JavaScript w sprawdzonym przepływie.

Testy PHP wykonano lokalnie w PHP WASM (runtime zgłasza PHP 8.5), a panel w WordPress Playground z SQLite. Adapter testowy zgłasza ostrzeżenie o przestarzałej metodzie PDO w PHP 8.5; nie dotyczy kodu wtyczki. Nie zastępuje to testu konkurencyjnych zakupów na docelowym MySQL/InnoDB.

Nie wykonano instalacji aktualizacji z publicznego GitHub Release — wydanie nie zostało opublikowane. Pipeline GitHub Actions przygotowano lokalnie; nie był uruchamiany na GitHubie. Rzeczywiste Stripe/BLIK, dostarczalność SMTP i hosting nadal wymagają odbioru na docelowym środowisku.

## Wydanie 0.3.0 — 01.10.2026

- Next.js: kompilacja produkcyjna i TypeScript; składnia wszystkich plików PHP.
- 47 sprawdzeń integracyjnych: płatności i powtórzenia webhooków, kolizje miejsc, trzy mecze w pakiecie, jeden QR pakietu i niezależne wejścia, odrzucenie ponownego skanu i obcego meczu, limit kibica z uwzględnieniem oczekujących płatności, ceny meczu, pakiet z nieznanym terminem, PDF.
- 20 sprawdzeń domeny, 20 aktualizatora i 5 wersjonowania wydania.
- 12 sprawdzeń tożsamości Google / podpisów Wallet: podpis, odbiorca, wystawca, nonce, ważność, potwierdzony e-mail, algorytm i klucz; zachowanie właściwego QR i listy meczów.
- Lokalny WordPress: 11 podstron panelu, ustawienia inline, mecze, mini-karnet, promocja, voucher, PDF, blokada/zwolnienie, eksport i uprawnienia biletera. Dodatkowo ceny globalne / jednego meczu, blokowanie wszystkich meczów z pełnym wycofaniem przy kolizji, lista bileterów i upload grafiki.
- Skaner WordPress: odczyt QR z rzeczywistego PDF, pierwsze wejście przyjęte, drugie odrzucone; widok 390 × 844 bez przewijania.
- Rejestracja i logowanie klienta, odświeżenie nonce, brak Stripe nie pozostawia blokady, chroniony PDF.
- PDF wyrenderowany i obejrzany; QR odczytany z obrazu PDF i porównany z tokenem.
- Mobilny WebKit: kafle → sektor → miejsce → logowanie, bez przepełnienia poziomego, pełna wysokość osadzonego koszyka, tylko wolne/zajęte, brak linku Bileter w sklepie; działa /bileter.

Publiczna strona przekierowuje /kup-bilet na /kup-bilety/. W odczycie WebKit nie wystąpił całkowicie pusty ekran. Potwierdzono ucinanie koszyka przez wysokość iframe i błędne względne adresy czcionek; poprawiono je. Odbiór na fizycznym iPhonie i test aparatu pozostają konieczne.

Testy lokalne używają SQLite i atrap Stripe, więc nie potwierdzają współbieżności InnoDB, rzeczywistych płatności, doręczenia poczty ani akceptacji portfeli przez konta Apple/Google klubu. Konfiguracja i testy produkcyjne tych usług wymagają właściwych kluczy/certyfikatów. Test Apple w CI sprawdza archiwum, manifest i podpis na certyfikacie jednorazowym; nie jest certyfikatem klubu.
