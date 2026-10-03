## Wydanie 0.5.0

- Google loguje istniejące połączone konto; zweryfikowane adresy Gmail/Workspace łączą się z istniejącym kontem bez hasła. Dla adresu spoza hostingu Google pierwsze połączenie wymaga jednorazowego linku e-mail w tej samej przeglądarce. Podpis, wystawca, odbiorca, nonce, state i PKCE nadal są weryfikowane. Źródło zasad zaufania: https://developers.google.com/identity/sign-in/web/backend-auth.
- **Przed użyciem Google dodaj w Google Cloud → OAuth client → Authorized redirect URIs dokładnie `https://deckapelplin.pl/konto/google/powrot/`.** Wtyczka pokazuje właściwy adres dla swojej domeny w Ustawienia → Konta kibiców. Stary adres można zachować jako dodatkowy podczas aktualizacji. Nowe logowania korzystają z publicznych tras `/konto/google/start/` i `/konto/google/powrot/`; nie są to strony WordPressa do ręcznego tworzenia. Wyklucz `/konto/google/*` z cache/CDN.
- Rejestracja zbiera imię, nazwisko, ulicę, numer domu, opcjonalny numer mieszkania, polski kod pocztowy, miejscowość, telefon i e-mail. Nowe konto Google uzupełnia dane przed zakupem. Dane adresowe pozostają prywatnymi danymi konta; zakup gościnny zachowuje dotychczasowy krótki formularz.
- Bilety mają stabilny numer `DK-000000001` i kod kreskowy Code 128 w PDF. Mini-karnet używa jednego numeru dla miejsca we wszystkich swoich meczach. Numer identyfikuje dokument, a **wejście nadal weryfikuje podpisany QR**, nie sam numer biletu. Istniejące QR pozostają bez zmian. Ponowne pobranie PDF dodaje kod kreskowy także do starszych biletów.
- Szczegóły zamówienia grupują miejsca pod meczem z datą, halą, sektorem, rzędem, numerem miejsca i numerem biletu. Filtr meczu znajduje się w treści nad danymi; kafle administracyjne pokazują całą grafikę 1920:1008.
- Usunięcie biletera odbiera tylko dostęp do skanera — również administratorowi — bez usuwania konta i pozostałych ról. Dostęp można przywrócić wyszukaniem konta.
- Skaner wybiera jeden najbliższy nieodwołany mecz z niewygasłym oknem wejścia, preferując mecz już otwarty. Pokazuje licznik poprawnych wejść / ważnych wydanych biletów (płatne, bezpłatne, vouchery); odświeża go po skanie i co 5 sekund. Przed otwarciem wejścia kamera jest wyłączona.
- Przy limicie REST API GitHuba aktualizator pobiera publiczny manifest wydania bez tego limitu. Zachowuje weryfikację wersji, nazwy paczki, wymagań i sumy SHA-256.

## Wydanie 0.4.0

- Główna aplikacja działa samodzielnie pod `/bilety/`, bez nagłówka motywu WordPressa; skaner pod `/skaner/`. Poprzednie adresy przekierowują na nowe. Wyklucz te dwie ścieżki i API `decka/v1` z cache stron/CDN.
- Zakup gościnny: e-mail, imię, nazwisko, opcjonalny telefon. Konto nie jest tworzone. Zamówienia i pobrania w tej przeglądarce chroni losowy identyfikator w ciasteczku HttpOnly oraz powiązane zabezpieczenie żądań. E-mail sam nie daje dostępu do zamówień. Bilet przychodzi w załączniku po potwierdzonej płatności; bezpłatne bilety i vouchery nie wymagają płatności.
- Kafle meczów mają ograniczoną szerokość i pokazują całą grafikę w proporcji 1920:1008. PDF dopasowuje wysokość grafiki do proporcji i mieści bilet na jednej stronie A4 (skrajnie wysokie obrazy są proporcjonalnie zmniejszane).
- Synchronizacja ustawia wejście od 2 godzin przed rozpoczęciem do 2 godzin po rozpoczęciu meczu. Ręcznie zmienione godziny mają pierwszeństwo. Edycja meczu pozwala wrócić do automatycznych godzin. Zmiana terminu nadal zamyka sprzedaż do ponownego otwarcia przez klub.
- Raporty obejmują normalne, ulgowe, vouchery, bezpłatne, oczekujące, sprzedaż, wejścia i przychody per mecz. Wykresy zapełnienia dotyczą 340 miejsc B/C/D, a nie wszystkich miejsc fizycznych w hali.
- Aktualizację można zainstalować przyciskiem przez standardowy, chroniony mechanizm WordPressa. Diagnostyka Stripe odczytuje aktywne środowisko, klucz API i konfigurację webhooka; osobno pokazuje, czy odebrano już poprawnie podpisany webhook. Nie tworzy transakcji i nie zastępuje próby płatności BLIK na hostingu.
- Apple Wallet i Google Wallet mają osobne przełączniki. Wyłączenie ukrywa konfigurację i wyłącza wydawanie portfeli; zapisane klucze pozostają zachowane.
- Zespół bileterów: wyszukiwanie po fragmencie imienia/nazwiska/loginu/e-maila, nadawanie oraz usuwanie dostępu bez kasowania konta kibica. Skaner nie ma ręcznego wpisywania kodu; wynik pokazuje przez 2 sekundy, następnie wraca do kamery. Zielony oznacza wejście, żółty wykorzystany bilet, niebieski inny mecz, czerwony pozostałe błędy.

## Wydanie 0.3.6

- Migracja rozdziela kolizje `request_once` ze starej bazy, także wynikające z różnych reguł porównywania tekstu. Najstarsze zamówienie zachowuje dotychczasowy klucz ponowienia, kolejne otrzymują deterministyczne klucze techniczne. Identyfikatory zamówień, płatności, bilety i QR pozostają bez zmian; źródłowe tabele nie są modyfikowane. Raport podaje liczbę zmienionych kluczy. Każdy inny konflikt nadal bezpiecznie wycofuje całą migrację.
- Ustawienia podzielono na siedem kategorii: Sprzedaż, Płatności, Konta kibiców, Bilety i e-mail, Portfele, Liga, System. Edycja pozostaje bezpośrednio na stronie, ze wspólnym przyciskiem zapisu. Zmiana zakładki zachowuje wpisane wartości.
- W Ustawienia → System bieżący stan bazy i zalecane działanie są na górze. Historia, struktura tabel i JSON są schowane w szczegółach. Przycisk „Pobierz raport” zapisuje diagnostykę bez danych klientów i kluczy Stripe.
- Przy niedokończonej migracji użyj „Ponów migrację”. „Sprawdź stan bazy” wykonuje wyłącznie diagnozę. Aktualizacje i zadania w tle mają oddzielne karty.

# Decka Bilety 0.5.0

Wtyczka WordPress z interfejsem Next.js, mapą 340 miejsc z pliku Miejsca-Decka-Online.xlsx, Stripe Checkout i kontrolą wejść. Wydanie do instalacji i odbioru na środowisku testowym. Nie podłączono konta Stripe klubu ani docelowego hostingu.

## Instalacja

1. Na kopii testowej strony wybierz **Wtyczki → Dodaj wtyczkę → Wyślij wtyczkę na serwer** i wskaż `decka-bilety-0.5.0.zip`. Aktywuj wtyczkę.
2. Wymagania: WordPress 6.6+, PHP 8.2+, MySQL/MariaDB z InnoDB, HTTPS, rozszerzenia PHP OpenSSL, cURL, DOM, mbstring, GD i zlib; dla Apple Wallet także ZIP. Wtyczka zawiera bibliotekę PDF i zbudowany interfejs — na hostingu nie trzeba instalować Node.js, Next.js ani Composera. Przy zbyt niskim limicie uploadu rozpakuj paczkę i prześlij folder `decka-bilety` do `wp-content/plugins/`.
3. Sprzedaż działa bezpośrednio pod **/bilety/** bez nagłówka motywu. Nie trzeba tworzyć strony WordPress. Krótki kod `[decka_bilety]` pozostaje dostępny do osadzenia na innych stronach.
4. Skaner działa pod adresem **/skaner/** (logowanie kontem obsługi). Nie trzeba tworzyć strony WordPress.
5. Ustaw w WordPressie politykę prywatności, a w **Decka Bilety → Ustawienia** adres regulaminu sprzedaży.
6. Skonfiguruj niezawodną wysyłkę SMTP w WordPressie. PDF jest przekazywany do `wp_mail` jako załącznik; pozytywna odpowiedź oznacza przyjęcie przez system pocztowy, nie gwarancję doręczenia do skrzynki.
7. Zapewnij wywoływanie WP-Cron co minutę przez harmonogram hostingu. Kolejka sprawdza płatności, zwalnia potwierdzone wygasłe sesje i wysyła bilety. Przycisk **Sprawdź płatności i kolejkę e-mail** pozwala uruchomić ją ręcznie. Na stronie bez ruchu sam WP-Cron może reagować z opóźnieniem.
8. Wyłącz cache stron/CDN dla `/bilety/`, `/skaner/`, `wp-admin/admin-post.php?action=decka_app`, `wp-json/decka/v1/*` oraz pobierania PDF. Strony z krótkim kodem mogą być cache'owane, ale sam osadzony interfejs i API muszą pozostawać dynamiczne.

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
- Klient widzi tylko wolne i zajęte miejsca oraz swój wybór. Panel klubu zachowuje osobne kolory opłaconych, oczekujących, voucherów i blokad.
- Dostępność odświeża się co 8 sekund, a serwer sprawdza ją ponownie podczas zakupu. Brak połączenia wyłącza możliwość wyboru.
- Maksymalnie 10 miejsc w jednym zamówieniu. Wybór w koszyku nie blokuje miejsc; blokada następuje przy tworzeniu płatności po podaniu danych gościa lub zalogowaniu.
- Sesja Checkout trwa około **31 minut**. Miejsce jest zwalniane dopiero po potwierdzeniu wygaśnięcia/anulowania przez Stripe, a nie po samym upływie lokalnego zegara. Przy niejednoznacznym błędzie sieci blokada pozostaje, a wtyczka ponawia zapytanie z tym samym kluczem operacji.

## Mecze i rozpoczęcie sprzedaży

W **Ustawieniach** zapisano adres oficjalnego terminarza PZKosz i ID Decki `7625`. Przycisk **Pobierz domowe mecze ze strony ligowej** pobiera aktualne domowe spotkania. Import jest ręczny; nie uruchamia sprzedaży automatycznie.

Importer korzysta ze stałych ID meczów ze strony ligowej. Ponowny import aktualizuje rekordy zamiast tworzyć duplikaty. Mecze bez potwierdzonej godziny trzeba uzupełnić w panelu przed otwarciem sprzedaży. Godziny formularzy są w strefie Europe/Warsaw, zapis bazy w UTC.

Przy każdym meczu można ustawić przeciwnika, halę, termin, przedział godzin wejścia, sprzedaż otwartą/zamkniętą i odwołanie. Importowana zmiana terminu zamyka sprzedaż i wejścia do ponownej decyzji administratora. Nie wysyła automatycznego powiadomienia o zmianie terminu; klub powinien powiadomić posiadaczy biletów. PDF pobrany ponownie pokazuje aktualny termin.

## Konto, bilety i bileter

Kibic wybiera miejsca, następnie loguje się lub zakłada konto (co najmniej 12 znaków hasła), wybiera rodzaje biletów i przechodzi do Stripe. Domyślne ceny: **normalny 25 zł, ulgowy 15 zł**; można je zmienić w ustawieniach. Warunki uprawnienia do ulgi należy opisać w regulaminie klubu.

Po potwierdzeniu płatności powstaje osobny bilet A4 z QR dla każdego miejsca. Mini-karnet ma jeden dokument i jeden QR na wszystkie objęte mecze; wejścia są rejestrowane osobno dla każdego meczu. Całe zamówienie trafia do jednego wielostronicowego PDF. Plik jest wysyłany e-mailem i dostępny w **Moje bilety**. Nie jest przechowywany w publicznym katalogu; pobieranie wymaga konta właściciela lub administratora. QR nie zawiera danych osobowych.

W WordPress **Użytkownicy → Dodaj użytkownika** utwórz konto obsługi i nadaj rolę **Bileter Decka**. Bileter loguje się na dedykowanej stronie, wybiera mecz i skanuje aparatem telefonu albo wkleja kod z czytnika. Każdy poprawny skan od razu zapisuje wejście. Sprawdzane są: podpis QR, mecz, środowisko, status biletu, przedział wejść oraz wcześniejsze wykorzystanie. Przy bilecie ulgowym panel przypomina o sprawdzeniu uprawnienia.

Aparat wymaga HTTPS i zezwolenia przeglądarki. Skanowanie wymaga internetu; nie ma trybu offline. Zmiana WordPress `SECURE_AUTH_SALT` unieważnia dotychczasowe QR — nie należy jej wykonywać bez zaplanowanej wymiany biletów.

## VOUCHER, mini-karnety i promocje

**VOUCHER:** administrator wybiera mecz i miejsca na mapie w podstronie Bilety i vouchery oraz podaje e-mail odbiorcy. Powstaje bezpłatny bilet z napisem VOUCHER i QR; wysyłka trafia do kolejki. W panelu klubu miejsce jest oznaczone jako voucher, a klient widzi je jako zajęte. Wydany voucher jest przypisany do konta administratora; odbiorca korzysta z załącznika e-mail.

**Mini-karnet:** podaj nazwę, ID od 2 do 20 wybranych meczów, cenę normalną i ulgową za cały pakiet, opcjonalne daty dostępności i aktywuj ofertę. Przykład: ID trzech kolejnych domowych meczów. To samo miejsce musi być dostępne na każdym z nich. Cały pakiet jest rezerwowany w jednej transakcji. Pakiet ma własny przełącznik sprzedaży; mecze nie muszą być otwarte w sprzedaży pojedynczej. Zmiana zawartości oferty nie zmienia już kupionych biletów.

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


## Panel administratora 0.3.0

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

Konfigurację wydawania paczek i przycisku aktualizacji opisuje [REPOZYTORIUM.md](REPOZYTORIUM.md). Po połączeniu PR-a do main i udanych testach GitHub Actions automatycznie publikuje kolejną wersję.

## Zmiany w 0.3.0 i konfiguracja integracji

Zakup zaczyna się od kafli otwartych meczów, a dalej aktywnych mini-karnetów. Po wyborze produktu pojawia się mapa hali. Mini-karnet wymaga potwierdzenia informacji o możliwych zmianach terminów, z zachowaniem ustawowych praw konsumenta. Pakiet może zawierać mecz bez daty; wejście wymaga ustawienia okna wejść. Zmiana listy meczów oferty nie zmienia już wydanych biletów.

Ustawienia są widoczne bez popupu. Zmiana globalnych cen aktualizuje wszystkie mecze; edycja cen meczu zmienia tylko ten mecz. Limit biletów na kibica na mecz uwzględnia poprzednie opłacone i oczekujące zakupy oraz mini-karnety. Jednorazowo nadal można kupić do 10 miejsc. Vouchery administratora nie podlegają limitowi zakupów kibica.

Każdy mecz może mieć grafikę JPG/PNG (do 8 MB i 6000 px), pokazywaną na kaflu i w PDF. Plan hali z filtrem „Wszystkie mecze” pokazuje sumę zajętości. Blokada wielu meczów jest atomowa: jakakolwiek kolizja z zamówieniem wycofuje całą operację. Nowe mecze dodane później nie dziedziczą ręcznych blokad.

**Stripe:** `sk_test_…` / `sk_live_…` to klucz serwera. `pk_…` jest kluczem publicznym i w tym modelu przekierowania Checkout nie jest potrzebny. `whsec_…` nie znajduje się na liście kluczy API: w Stripe otwórz **Workbench → Webhooks → Add destination**, wybierz swoje konto i zdarzenia snapshot wymienione powyżej, wpisz URL ze strony ustawień, zapisz i odsłoń **Signing secret**. Osobno skonfiguruj test i produkcję. [Dokumentacja Stripe](https://docs.stripe.com/webhooks).

**Logowanie Google:** w Google Cloud skonfiguruj ekran zgody OAuth i klienta typu Web application. Wklej Client ID i Client secret do ustawień wtyczki. Authorized redirect URI musi być dokładnie adresem pokazanym w panelu, np. `https://deckapelplin.pl/konto/google/powrot/`. Nowi kibice mogą zarejestrować się przez Google; istniejący logują się hasłem i wybierają „Połącz konto Google”. Istniejące konta z potwierdzonym adresem Gmail/Workspace są łączone automatycznie; inne adresy wymagają jednorazowego potwierdzenia e-maila. [Google OIDC](https://developers.google.com/identity/openid-connect/openid-connect).

**Apple Wallet:** potrzebne aktywne konto Apple Developer, Pass Type ID, Team ID, certyfikat Pass Type i odpowiadający mu klucz prywatny w PEM oraz aktualny certyfikat pośredni Apple WWDR. Pola są w ustawieniach, sekrety są szyfrowane. Serwer generuje podpisany plik `.pkpass`, a nie przemianowany PDF. [Certyfikaty Apple](https://developer.apple.com/help/account/capabilities/create-wallet-identifiers-and-certificates/).

**Google Wallet:** potrzebne konto wydawcy z prawem publikacji, Issuer ID i klucz JSON konta usługi mającego dostęp do wydawcy. Wtyczka podpisuje link „Dodaj do Google Wallet”. W trybie demo Google ogranicza odbiorców do testerów. [Google Wallet](https://developers.google.com/wallet/generic/web).

Przyciski portfeli są dostępne przy opłaconych biletach na koncie kibica po konfiguracji. Portfele zawierają ten sam QR co PDF. Nie wdrożono push aktualizacji portfeli: przy zmianie terminu należy sprawdzić konto kibica, a klub powiadamia kibiców. Skaner zawsze sprawdza bieżący status w bazie, również po zwrocie lub odwołaniu meczu. Produkcyjne akceptowanie passów wymaga prób z prawdziwymi certyfikatami/kontami klubu.

**iOS:** poprawiono wysokość osadzenia (pełny koszyk), ścieżki czcionek i dodano pełnoekranowy link awaryjny. Widok działa w lokalnym mobilnym WebKit. Całkowicie pustego widoku na publicznej stronie nie udało się odtworzyć; wymagany odbiór na zgłoszonym fizycznym iPhonie. Test WebKit nie zastępuje testu aparatu i uprawnień na urządzeniu.


## Błąd zapisu przy przejściu do płatności (0.3.1)

Wydanie ponawia aktualizację struktury bazy i sprawdza obecność wymaganych kolumn przed oznaczeniem jej jako ukończonej. Nie usuwa zamówień ani biletów. W Ustawieniach dodano sekcję **Baza danych** i przycisk **Sprawdź i uzupełnij strukturę bazy**.

Jeśli zapis zamówienia zostanie odrzucony, klient otrzyma kod `DB-ORDERS-…`, `DB-INVENTORY-…` albo `DB-ITEMS-…`. Odpowiadający mu ostatni błąd (czas, etap, kategoria i nazwa pola, jeśli rozpoznana) znajduje się w sekcji Baza danych. Diagnoza jest zapisywana po wycofaniu transakcji, dzięki czemu nie znika razem z nieudanym zamówieniem. Nie zapisujemy w niej zapytania SQL, danych kibica ani sekretów Stripe. Nieudany zapis nie jest automatycznie ponawiany i nie rozpoczyna płatności.

Brak kolumn można naprawić ponowną aktualizacją struktury. Brak uprawnień, dodatkowe wymagane kolumny, błędy kodowania lub ograniczenia hostingu wymagają działania na podstawie wskazanej kategorii. Nie należy zakładać, że sam komunikat zapisu oznacza błędne klucze Stripe. Zgłoszonego błędu konkretnego hostingu nie odtworzono na czystym MySQL; to wydanie dostarcza naprawę niepełnej migracji oraz diagnostykę potrzebną do dalszego ustalenia przyczyny.

## Naprawa konfliktu unikatowego zapisu (0.3.2)

Nowe zamówienie zapisuje brak sesji Stripe jako SQL NULL. Aktualizacja naprawia również starszą definicję pola `session_id` (NOT NULL lub domyślne puste ciągi) i zamienia wyłącznie puste identyfikatory sesji na NULL. Zachowuje zamówienia, bilety, niepuste identyfikatory Stripe i indeks unikatowy. Migracja uruchamia się po aktualizacji; można ją ponowić w Ustawienia → Baza danych.

Diagnostyka konfliktu pokazuje teraz nazwę indeksu, bez wartości powodującej konflikt. Sam komunikat z wersji 0.3.1 nie wskazuje, który indeks zawiódł: naprawa dotyczy odtworzonego scenariusza pustej sesji, a jej skuteczność na hostingu wymaga ponowienia zakupu. Jeżeli błąd pozostaje, przekaż kod oraz pole „Indeks” z nowego wpisu. Nie usuwaj zamówień ani indeksów unikatowych.

Poprawiono również formatowanie definicji tabel dla dbDelta: przecinki w domyślnym adresie hali nie są dzielone na osobne definicje kolumn.

## Baza dect_ i pełna kontrola zapisu (0.3.3)

Wtyczka używa teraz własnych tabel `{prefiks WordPressa}dect_*` (np. `wp_dect_orders`). Aktualizacja tworzy osiem tabel na podstawie aktualnego schematu i jednorazowo przenosi wspólne kolumny z `{prefiks WordPressa}decka_*`. Nie kopiuje obcych pól, indeksów ani triggerów, w tym zgłoszonego `order_number`. Stare tabele i dodatkowe dane pozostają nienaruszone. Nie potwierdzono, z jakiej wtyczki pochodził ten indeks.

Migracja zachowuje identyfikatory, kwoty, kody QR, sesje Stripe, rezerwacje, statusy oraz historię wejść. Porównuje liczbę i wartości kopiowanych rekordów. Wszystkie dane i znacznik przełączenia zatwierdza w jednej transakcji; błąd wycofuje kopiowanie, blokuje zakup i nie usuwa źródła. Nie nadpisuje niepustych tabel docelowych i nie powtarza kopiowania po udanym przełączeniu. Od 0.3.5 znacznik migracji jest zatwierdzany w dedykowanej tabeli InnoDB `dect_state`; tabela opcji WordPressa może pozostać MyISAM. Ustawienia i konta WordPressa pozostają bez zmiany nazw.

Aktualizację wykonaj w oknie serwisowym, bez trwających żądań zakupu lub skanowania ze starej wersji PHP. Po przełączeniu starsza wersja wtyczki nie odczyta nowych transakcji z dect_; nie należy wracać do starego ZIP-a jako metody cofania danych. Stare tabele są archiwum, nie stale synchronizowaną kopią.

Ustawienia → Baza danych pokazują aktywną przestrzeń, wynik migracji i kontrolę wszystkich ośmiu tabel: kolumny, typy, długości, NULL, wartości domyślne, AUTO_INCREMENT, InnoDB oraz indeksy unikatowe. Raport nie zawiera wierszy klientów ani wartości sekretów. Dodatkowe ograniczenia są zgłaszane bez automatycznego usuwania danych.

Kontrolowany jest również zapis odpowiedzi Stripe, danych potwierdzonej płatności, biletów i znaczników wysyłki. Błąd bazy po płatności nie jest uznawany za powodzenie; webhook może ponowić próbę. Testy używają atrap Stripe i poczty — odbiór na hostingu i prawdziwa płatność pozostają osobnym sprawdzeniem.

## Dostęp do diagnostyki przed migracją (0.3.4)

Przycisk „Sprawdź pełną strukturę” nie zapisuje historii w tabeli audit. Dzięki temu działa także przed zakończeniem migracji i przy uszkodzonej tabeli historii. Ustawienia można zapisać przed migracją bez próby zapisu do jeszcze zablokowanych tabel. Komunikat blokady informuje o nieukończonym przełączeniu, zamiast sugerować, że migracja cały czas trwa.

Kontrola struktury jest odczytem; nie wykonuje migracji. Aby ponowić migrację i zobaczyć konkretny powód jej niepowodzenia, użyj „Sprawdź i uzupełnij strukturę bazy”.

## Status migracji i odzyskiwanie po błędzie (0.3.5)

- „Sprawdź strukturę i stan danych”: odczytuje strukturę oraz liczby rekordów w źródle i celu. Aktualizuje raport, bez kopiowania danych.
- „Napraw strukturę i ponów migrację”: tworzy/uzupełnia tabele, sprawdza je i przenosi dane. Ponowienie nie nadpisuje niepustego celu. Po sukcesie przycisk nazywa się „Sprawdź i napraw aktywną bazę”.
- Wynik „Pola i indeksy nowych tabel są zgodne” nie oznacza zakończenia migracji. Decydujący jest status „Nowa baza jest aktywna”.
- Nieudana próba zapisuje konkretny etap, tabelę, bezpieczną przyczynę i czas. Raport odświeża się także po nieudanej operacji. Stary błąd zapisu jest wyraźnie oznaczony jako historyczny.

Poprzedni migrator niepotrzebnie wymagał InnoDB dla wp_options. Teraz własny znacznik dect_state jest zatwierdzany razem z danymi, a opcja WordPressa jest tylko kopią dla zgodności. Jeśli jej aktualizacja lub pamięć podręczna zawiedzie, wtyczka rozpoznaje zatwierdzenie we własnej tabeli i nie kopiuje danych drugi raz. Kod nie zmienia silnika tabel WordPressa.

Podczas nieukończonej migracji klient otrzymuje komunikat o czasowej niedostępności sprzedaży i HTTP 503, zamiast ogólnego błędu pobierania oferty. Przyczyna konkretnego hostingu wymaga odczytu wyniku migracji — poprawny raport struktury nie wskazuje, na którym etapie zatrzymał się transfer.

### Weryfikacja interfejsu 0.4.0

Po zbudowaniu frontendu uruchom `node tests/shop-ui.mjs`, `node tests/scanner-ui.mjs` i `node tests/settings-ui.mjs` z dostępnym Playwright/Chromium. Opcjonalne zmienne `DECKA_PLAYWRIGHT_MODULE` i `CHROME_PATH` wskazują lokalną instalację. Testy korzystają z izolowanych odpowiedzi API. Sprawdzają zakup gościnny, brak PDF przed płatnością, proporcje kafelków, obliczenia raportów, przełączniki portfeli i dynamiczne wyszukiwanie bileterów. Test skanera dekoduje prawdziwy QR z syntetycznego obrazu kamery i sprawdza cztery stany oraz automatyczne wznowienie po 2 sekundach. Nie zastępuje to sprawdzenia aparatu na fizycznym telefonie.
