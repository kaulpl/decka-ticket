# Decka Bilety 0.2.0

Wtyczka WordPress z interfejsem Next.js, mapą 340 miejsc z pliku Miejsca-Decka-Online.xlsx, Stripe Checkout i kontrolą wejść. Wydanie do instalacji i odbioru na środowisku testowym. Nie podłączono konta Stripe klubu ani docelowego hostingu.

## Instalacja

1. Na kopii testowej strony wybierz **Wtyczki → Dodaj wtyczkę → Wyślij wtyczkę na serwer** i wskaż `decka-bilety-0.2.0.zip`. Aktywuj wtyczkę.
2. Wymagania: WordPress 6.6+, PHP 8.2+, MySQL/MariaDB z InnoDB, HTTPS, rozszerzenia PHP OpenSSL, cURL, DOM, mbstring i zlib. Wtyczka zawiera bibliotekę PDF i zbudowany interfejs — na hostingu nie trzeba instalować Node.js, Next.js ani Composera. Przy zbyt niskim limicie uploadu rozpakuj paczkę i prześlij folder `decka-bilety` do `wp-content/plugins/`.
3. Utwórz stronę **Bilety** z blokiem Krótki kod: `[decka_bilety]`.
4. Utwórz osobną stronę **Bileter** z kodem `[decka_bileter]`.
5. Ustaw w WordPressie politykę prywatności, a w **Decka Bilety → Ustawienia** adres regulaminu sprzedaży.
6. Skonfiguruj niezawodną wysyłkę SMTP w WordPressie. PDF jest przekazywany do `wp_mail` jako załącznik; pozytywna odpowiedź oznacza przyjęcie przez system pocztowy, nie gwarancję doręczenia do skrzynki.
7. Zapewnij wywoływanie WP-Cron co minutę przez harmonogram hostingu. Kolejka sprawdza płatności, zwalnia potwierdzone wygasłe sesje i wysyła bilety. Przycisk **Sprawdź płatności i kolejkę e-mail** pozwala uruchomić ją ręcznie. Na stronie bez ruchu sam WP-Cron może reagować z opóźnieniem.
8. Wyłącz cache dla `wp-admin/admin-post.php?action=decka_app`, `wp-json/decka/v1/*` oraz pobierania PDF. Strony z krótkim kodem mogą być cache'owane, ale sam osadzony interfejs i API muszą pozostawać dynamiczne.

## Stripe — test i produkcja

W ustawieniach są osobne klucze tajne i sekrety webhook dla TEST oraz PRODUKCJA. Domyślnie aktywny jest TEST. Klucze nigdy nie trafiają do przeglądarki. Przechowywane w bazie sekrety są szyfrowane kluczem powiązanym z WordPress AUTH salt; zmiana soli wymaga ponownego zapisania sekretów.

Alternatywnie administrator serwera może umieścić sekrety w `wp-config.php` jako stałe `DECKA_STRIPE_TEST_SECRET`, `DECKA_STRIPE_TEST_WEBHOOK`, `DECKA_STRIPE_LIVE_SECRET`, `DECKA_STRIPE_LIVE_WEBHOOK`. Stałe mają pierwszeństwo przed panelem.

1. W Stripe aktywuj metody **karta** oraz **BLIK**. Waluta płatności to PLN. Dostępność BLIK zależy od aktywacji tej metody na koncie Stripe.
2. W ustawieniach wtyczki wpisz klucz `sk_test_…` oraz sekret `whsec_…` dla testowego webhooka.
3. Adresy webhooków są pokazane w panelu. Standardowo:
   - `https://twoja-domena.pl/wp-json/decka/v1/webhook/test`
   - `https://twoja-domena.pl/wp-json/decka/v1/webhook/live`
4. Włącz zdarzenia: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `charge.refunded`, `charge.dispute.created`.
5. Wykonaj zakup testowy kartą i BLIK, sprawdź dostarczenie webhooka, PDF, e-mail i wejście na mecz. Następnie skonfiguruj odrębne klucze i webhook LIVE i przełącz tryb.

Przełącznik oddziela zamówienia, zajętość miejsc i wykorzystanie kodów rabatowych. Mecze, ceny, oferty i konta są wspólną konfiguracją. „Moje bilety” pokazuje oba środowiska z oznaczeniem TEST. Bileter przyjmuje wyłącznie bilety z aktualnie wybranego środowiska.

Potwierdzenie na stronie powrotu ze Stripe nie wystarcza do wystawienia biletu. Wtyczka weryfikuje podpis webhooka, pobiera sesję ze Stripe i porównuje środowisko, identyfikator zamówienia, walutę oraz kwotę. Powtórzone potwierdzenie nie wydaje kolejnego biletu.

## Mapa miejsc

- Tylko sektory potwierdzone przez klub: **B — 105, C — 130, D — 105**, razem **340 miejsc**.
- Osiem pól „KAMERA” nie jest miejscami sprzedażowymi. Nie dodano sektora A ani brakujących numerów.
- Położenie i numery pochodzą z komórek Excela, w tym szerszego górnego rzędu. Puste komórki zachowują odstępy.
- Excel nie zawiera nazw rzędów: przyjęto **rząd 1 najbliżej boiska, rząd 8 najdalej**. Numery miejsc pozostają dokładnie takie jak w pliku.
- Najpierw widać plan hali z kropkami; kliknięcie sektora otwiera widok z numerami. Na małym telefonie powiększony sektor można przesuwać poziomo.
- Wolne: granatowe. Wybór kibica: zielony. **Opłacone: szare**. Oczekujące na płatność: bursztynowe. Voucher, bilet bezpłatny lub blokada po zwrocie: fioletowe.
- Dostępność odświeża się co 8 sekund, a serwer sprawdza ją ponownie podczas zakupu. Brak połączenia wyłącza możliwość wyboru.
- Maksymalnie 10 miejsc w jednym zamówieniu. Wybór w koszyku nie blokuje miejsc; blokada następuje przy tworzeniu płatności po zalogowaniu.
- Sesja Checkout trwa około **31 minut**. Miejsce jest zwalniane dopiero po potwierdzeniu wygaśnięcia/anulowania przez Stripe, a nie po samym upływie lokalnego zegara. Przy niejednoznacznym błędzie sieci blokada pozostaje, a wtyczka ponawia zapytanie z tym samym kluczem operacji.

## Mecze i rozpoczęcie sprzedaży

W **Ustawieniach** zapisano adres oficjalnego terminarza PZKosz i ID Decki `7625`. Przycisk **Pobierz domowe mecze ze strony ligowej** pobiera aktualne domowe spotkania. Import jest ręczny; nie uruchamia sprzedaży automatycznie.

Importer korzysta ze stałych ID meczów ze strony ligowej. Ponowny import aktualizuje rekordy zamiast tworzyć duplikaty. Mecze bez potwierdzonej godziny trzeba uzupełnić w panelu przed otwarciem sprzedaży. Godziny formularzy są w strefie Europe/Warsaw, zapis bazy w UTC.

Przy każdym meczu można ustawić przeciwnika, halę, termin, przedział godzin wejścia, sprzedaż otwartą/zamkniętą i odwołanie. Importowana zmiana terminu zamyka sprzedaż i wejścia do ponownej decyzji administratora. Nie wysyła automatycznego powiadomienia o zmianie terminu; klub powinien powiadomić posiadaczy biletów. PDF pobrany ponownie pokazuje aktualny termin.

## Konto, bilety i bileter

Kibic wybiera miejsca, następnie loguje się lub zakłada konto (co najmniej 12 znaków hasła), wybiera rodzaje biletów i przechodzi do Stripe. Domyślne ceny: **normalny 25 zł, ulgowy 15 zł**; można je zmienić w ustawieniach. Warunki uprawnienia do ulgi należy opisać w regulaminie klubu.

Po potwierdzeniu płatności powstaje osobny bilet A4 z QR dla każdego miejsca i meczu. Całe zamówienie trafia do jednego wielostronicowego PDF. Plik jest wysyłany e-mailem i dostępny w **Moje bilety**. Nie jest przechowywany w publicznym katalogu; pobieranie wymaga konta właściciela lub administratora. QR nie zawiera danych osobowych.

W WordPress **Użytkownicy → Dodaj użytkownika** utwórz konto obsługi i nadaj rolę **Bileter Decka**. Bileter loguje się na dedykowanej stronie, wybiera mecz i skanuje aparatem telefonu albo wkleja kod z czytnika. Każdy poprawny skan od razu zapisuje wejście. Sprawdzane są: podpis QR, mecz, środowisko, status biletu, przedział wejść oraz wcześniejsze wykorzystanie. Przy bilecie ulgowym panel przypomina o sprawdzeniu uprawnienia.

Aparat wymaga HTTPS i zezwolenia przeglądarki. Skanowanie wymaga internetu; nie ma trybu offline. Zmiana WordPress `SECURE_AUTH_SALT` unieważnia dotychczasowe QR — nie należy jej wykonywać bez zaplanowanej wymiany biletów.

## VOUCHER, mini-karnety i promocje

**VOUCHER:** administrator wybiera mecz i miejsca na mapie w podstronie Bilety i vouchery oraz podaje e-mail odbiorcy. Powstaje bezpłatny bilet z napisem VOUCHER i QR; wysyłka trafia do kolejki. Miejsce jest zajęte i fioletowe, nie szare. Wydany voucher jest przypisany do konta administratora; odbiorca korzysta z załącznika e-mail.

**Mini-karnet:** podaj nazwę, ID od 2 do 20 wybranych meczów, cenę normalną i ulgową za cały pakiet, opcjonalne daty dostępności i aktywuj ofertę. Przykład: ID trzech kolejnych domowych meczów. To samo miejsce musi być dostępne na każdym z nich. Cały pakiet jest rezerwowany w jednej transakcji. Sprzedaż każdego meczu w pakiecie musi być otwarta. Zmiana zawartości oferty nie zmienia już kupionych biletów.

**Promocja:** kod procentowy lub kwotowy (rabat w zł na całe zamówienie), limit użyć, zakres meczów, daty i włącznik. Limit użyć jest osobny dla TEST/LIVE i uwzględnia oczekujące zamówienia. Kod obejmujący tylko część meczów mini-karnetu jest odrzucany. Rabaty oblicza serwer. Nie łączy się wielu kodów. Przy rabacie 100% bilet jest wystawiany bez Stripe; dodatnia końcowa kwota musi wynosić co najmniej 2 zł.

## Statystyki i obsługa problemów

Tabela podaje aktywne opłacone normalne i ulgowe bilety, vouchery, bezpłatne bilety, wejścia, kwoty aktywnych opłaconych pozycji i oczekujące blokady, osobno dla każdego meczu i środowiska. Cena mini-karnetu oraz rabat są rozdzielane na wejścia. Eksport CSV zawiera każdą pozycję, typ, kwotę, e-mail, mecz, sektor, miejsce, status i czas wejścia. To raport biletowy; ostateczne rozliczenie opłat Stripe i zwrotów prowadź w Stripe.

W panelu widać ostatnie zamówienia i błędy wysyłki. Kolejka próbuje wysłać wiadomość maksymalnie 10 razy. **Wyślij ponownie** zeruje licznik. Biblioteka pocztowa może przyjąć mail, który następnie nie zostanie dostarczony — należy sprawdzić log SMTP.

Zwroty wykonuje się w Stripe. W tym wydaniu **każdy zwrot, także częściowy, oraz spór unieważnia całe zamówienie**. Miejsca pozostają zablokowane i nie wracają automatycznie do sprzedaży. Wtyczka nie obsługuje edycji pojedynczego zwracanego biletu, automatycznego ponownego otwarcia miejsca, faktur ani księgowania prowizji. Statystyka aktywnej sprzedaży wyłącza całe takie zamówienie; nie jest saldem finansowym częściowych zwrotów.

Jeśli błąd konfiguracji lub niejednoznaczna odpowiedź Stripe pozostawi zamówienie w stanie `creating`, napraw konfigurację i użyj przycisku sprawdzania płatności. Po 23 godzinach automatyczne próby tworzenia są wstrzymywane, aby nie odtwarzać płatności po wygaśnięciu klucza idempotencji. Administrator techniczny musi wtedy uzgodnić zamówienie z rejestrem Stripe. Nie wolno zwalniać miejsc na podstawie samego błędu przeglądarki.

## Odbiór przed uruchomieniem produkcji

Na docelowym hostingu trzeba potwierdzić:

- zakup kartą i BLIK w Stripe TEST, webhook oraz powrót z płatności;
- dostarczenie PDF przez klubowe SMTP;
- jednoczesny zakup tego samego miejsca z dwóch przeglądarek na MySQL/InnoDB;
- jednoczesny skan tego samego biletu przez dwóch bileterów;
- anulowanie i wygaśnięcie sesji, ponowiony webhook, zwrot;
- działanie aparatu na telefonach bileterów oraz zakres godzin wejścia;
- cron co minutę i wyłączenia cache;
- treść regulaminu, uprawnienia do ulg i godziny meczów.

Lokalne testy i ich zakres opisano w `RAPORT-TESTOW.md`. Nie zastępują one odbioru na docelowej bazie MySQL i rzeczywistym koncie Stripe.

## Dla osoby rozwijającej wtyczkę

`plugin/` — PHP, dane hali, biblioteka TCPDF i gotowy interfejs.
`frontend/` — źródła Next.js/React/TypeScript, lokalne fonty i lockfile.
`tests/` — testy logiki, integracji, przeglądarki i lokalnego WordPressa; dane testowe nie są dołączone do instalacyjnego ZIP.

Budowanie: `cd frontend`, `pnpm install --frozen-lockfile`, `pnpm build`, następnie skopiuj zawartość `frontend/out/` do `plugin/assets/`. Spakuj katalog `plugin/` pod nazwą `decka-bilety/`. Skrypt `scripts/package.py` tworzy paczkę instalacyjną i archiwum źródeł.

Mapa została odczytana bez modyfikowania źródłowego Excela. W `data/seats.json` zapisano komórki źródłowe i sumę SHA-256 pliku. Logo pochodzi z materiałów projektu Decka Pelplin.

Biblioteki: Next.js/React, ZXing, Lucide i TCPDF 6.11.4. Licencje zależności i fontów pozostają w paczce. Własny kod: GPL-3.0-or-later. Herb klubu pozostaje materiałem klubu.

## Dokumentacja źródłowa

- Stripe BLIK: https://docs.stripe.com/payments/blik
- Stripe Checkout: https://docs.stripe.com/api/checkout/sessions/create
- Webhooki Stripe: https://docs.stripe.com/webhooks
- Uwierzytelnianie WordPress REST: https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/
- Statyczny eksport Next.js: https://nextjs.org/docs/pages/guides/static-exports
- Terminarz PZKosz: https://rozgrywki.pzkosz.pl/liga/1/druzyny/d/7625/decka-pelplin/terminarz.html


## Panel administratora 0.2.0

Nowy panel Next.js jest osadzony w WordPressie i korzysta z REST API bez przeładowywania strony. Wszystkie odczyty i zapisy administracyjne wymagają uprawnienia `decka_manage` oraz prawidłowej sesji/nonce WordPressa.

- **Pulpit:** przychody, bilety, wejścia, vouchery, sprzedaż według meczu, problemy wysyłki.
- **Mecze:** tworzenie i edycja, okno wejść, uruchamianie sprzedaży, pobieranie z ligi.
- **Plan hali:** wyłącznie B/C/D, zbliżenie sektorów, ręczne blokady miejsc organizatora. Zwolnienie dotyczy tylko takich blokad, nigdy miejsc przypisanych do zamówienia.
- **Zamówienia:** wyszukiwanie, statusy, stronicowanie, szczegóły, PDF, kolejka ponownej wysyłki i odnośnik do płatności w Stripe.
- **Bilety i vouchery:** wyszukiwanie i statusy biletów, wystawianie voucherów, unieważnienie. Unieważnienie nie wykonuje zwrotu i nie uwalnia miejsca.
- **Mini-karnety:** wskazane mecze, ceny pakietu, terminy, aktywność.
- **Promocje i kody:** procent/kwota, limity użyć, okres i mecze objęte kodem.
- **Kibice:** lista kont powiązanych ze sprzedażą i ich podsumowania.
- **Wejścia i bileterzy:** historia skanów i nadawanie/odbieranie roli istniejącym kontom. Zmiany ról wymagają dodatkowych uprawnień WordPressa.
- **Raporty:** podział na mecze i rodzaje biletów, eksport CSV.
- **Ustawienia:** środowiska Stripe, ceny, liga, regulamin, kolor/stopka PDF, treść maila i aktualizacje.

Filtr meczu działa dla pulpitu, raportów, zamówień, biletów, planu hali i historii wejść. Katalog meczów, ofert, kibiców i ustawienia są wspólne. Raporty przychodów dotyczą opłaconych pozycji, nie salda rozliczeń Stripe.

Konfigurację wydawania paczek i przycisku aktualizacji opisuje [REPOZYTORIUM.md](REPOZYTORIUM.md). Zmiany nie zostały opublikowane z tego środowiska.
