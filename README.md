# STOCK — osobisty terminal inwestycyjny

Aplikacja po polsku, z responsywnym interfejsem HTML/CSS/JavaScript oraz opcjonalną częścią PHP + SQLite. Jeden portfel, ręczne transakcje, dowolne rynki i instrumenty, gotówka w wielu walutach, dywidendy, prowizje, wycena w PLN, FIFO, wykresy i kopie JSON. Nie wymaga npm ani procesu budowania.

## Podgląd lokalny

W katalogu projektu uruchom:

```sh
PORTFOLIO_DEV=1 php -S 127.0.0.1:8080 -t public
```

Otwórz http://127.0.0.1:8080. Tryb deweloperski działa wyłącznie dla połączeń loopback i pomija hasło. **Nie używaj PORTFOLIO_DEV na serwerze.** Pierwszy portfel na serwerze jest pusty. W Ustawieniach wybierz „Zobacz demo”, aby obejrzeć przykładowe pozycje. Demo jest oddzielone od danych prywatnych i nie zapisuje się do bazy.

Można też otworzyć `public/index.html` bez serwera (adres `file://`). Wtedy dane są zapisywane lokalnie w tej przeglądarce, nie synchronizują się między urządzeniami, a automatyczne notowania i RSS nie są dostępne. Lokalnie działa ręczna wycena, historia CSV, wszystkie operacje i eksport JSON. NBP może działać bez PHP, jeśli przeglądarka pozwala na połączenie z jego publicznym API.

## Twój serwer: komputer i telefon

Wymagania: PHP 8.2 lub nowszy, PDO SQLite, cURL, SimpleXML, DOM, OpenSSL, HTTPS oraz zapisywalny katalog prywatny. WebAuthn używa dołączonej biblioteki lbuchs/webauthn 2.2.0 (MIT), przypiętej do rewizji opisanej w `public/lib/webauthn/UPSTREAM.json`. Wymagane jest także mbstring.

1. Wgraj projekt. Ustaw **wyłącznie katalog `public/` jako document root** strony. `storage/`, `config.php`, testy i dokumentacja muszą znajdować się poza publicznym katalogiem. API odrzuca konfigurację z bazą wewnątrz document root.
2. Skopiuj `config.sample.php` do prywatnego `config.php` w katalogu głównym projektu. Wygeneruj hash hasła poniższym poleceniem. Hasło wpisujesz w terminalu; komenda nie zapisuje go w historii poleceń:

```sh
php -r '$p=readline("Hasło (min. 12 znaków): "); if(strlen($p)<12){fwrite(STDERR,"Za krótkie hasło\n");exit(1);} echo password_hash($p,PASSWORD_DEFAULT),PHP_EOL;'
```

Uwaga: `readline` pokazuje wpisywane znaki. Jeśli hasło wymaga ukrytego wprowadzania, wygeneruj hash w zaufanym narzędziu lokalnym. Nigdy nie używaj internetowego generatora haseł.

3. Ustaw `username` (domyślnie `digitalismundus`). Wygeneruj klucz szyfrowania 2FA poleceniem `php -r 'echo base64_encode(random_bytes(32)),PHP_EOL;'` i wklej go do `auth_key`. Zachowaj `must_change_password: true`, aby wymusić zmianę hasła startowego. Wklej hash jako `password_hash` w `config.php`. Pozostaw `secure_cookie: true`. Nadaj dostęp do katalogu prywatnego wyłącznie użytkownikowi PHP. Jeśli używasz hostingu współdzielonego, umieść konfigurację i `storage` poza `public_html` oraz dostosuj `$configFile` na początku `api.php` do ich **absolutnej** lokalizacji. Alternatywnie ustaw zmienne środowiskowe `PORTFOLIO_PASSWORD` (minimum 12 znaków), `PORTFOLIO_STORAGE` (ścieżka poza document root) i `PORTFOLIO_SECURE_COOKIE=1`.
4. Włącz HTTPS. Na Apache nagłówki i blokada listowania katalogów są w `.htaccess`. Na Nginx ustaw odpowiedniki: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin` oraz CSP podane w `.htaccess`; wyłącz listowanie katalogów. PHP wymaga standardowej konfiguracji FastCGI.
5. Otwórz tę samą stronę na komputerze i telefonie. Zaloguj się tym samym loginem i hasłem. Każdy zapis trafia do jednej bazy. Przycisk „Synchronizuj” pobiera aktualne dane; konflikt równoczesnych zmian nie nadpisuje drugiej wersji. Po konflikcie pobierz portfel i ponów niezapisaną zmianę.
6. W Safari na iPhonie użyj „Udostępnij → Do ekranu początkowego”. Manifest pozwala na otwieranie aplikacji jako osobnego okna. Aplikacja nie obiecuje pracy offline ani synchronizacji w tle. Ikona SVG może nie zostać użyta jako ikona ekranu początkowego we wszystkich wersjach iOS.

Sesje używają HttpOnly i SameSite Strict, limitu prób logowania i tokenu CSRF. Sesja wygasa po 30 minutach bezczynności lub po 8 godzinach. Zmiana hasła lub 2FA wylogowuje inne sesje. Dane na serwerze nie są szyfrowane osobnym kluczem; dostęp do serwera i kopii SQLite należy zabezpieczyć u operatora hostingu. Baza znajduje się w `storage/portfolio.sqlite`. Do kopii uruchom przy zatrzymanym serwerze albo użyj mechanizmu SQLite backup / `VACUUM INTO`; przy aktywnym WAL nie kopiuj samego pliku SQLite bez spójnej migawki. Eksport JSON obejmuje cały stan portfela, ale nie hasło.

## Jak rozpocząć

1. Dodaj wpłatę PLN lub waluty obcej. Jeżeli faktycznie wymieniasz PLN na USD, użyj przewalutowania, nie dwóch wpłat.
2. Dodaj instrument: symbol, nazwę, rynek, kategorię i walutę. GPW jest rynkiem; WIG i WIG20 to indeksy. Można dodać dowolny rynek lub typ „Inne”.
3. Wpisz symbol źródła np. `CDR.WA`, `AAPL`, `MSFT`, `VWCE.DE`, `BTC-USD`. Symbol instrumentu nie musi być identyczny z symbolem źródła. Waluta otrzymana ze źródła musi pasować do instrumentu. Notowania londyńskie w pensach GBp/GBX są normalizowane do GBP.
4. Dodaj zakup: data, jednostki, cena, faktyczna prowizja i historyczny kurs przeliczenia na PLN pobrany z NBP (z możliwością ręcznej korekty). Prowizja domyślna jest podpowiadana, ale zawsze można ją nadpisać. Minimalna prowizja w ustawieniach jest w walucie transakcji; dla brokerów z różnymi taryfami wpisuj realny koszt.
5. Pobierz ceny z widoku Pozycje / Rynki. Następnie w Ustawieniach pobierz kursy NBP. Możesz też ustawić ceny oraz kursy ręcznie.
6. Sprzedaż musi być poprzedzona wystarczającymi zakupami. Przy operacjach tego samego dnia obowiązuje kolejność wprowadzenia w ledgerze. Wynik sprzedaży i koszt pozostałych jednostek liczone są FIFO.
7. Dywidendy / odsetki wpisuj jako kwotę brutto, faktyczny podatek i prowizję. Do gotówki trafia kwota netto. Wypłata zmniejsza gotówkę i wpłaty netto. Opłata zmniejsza gotówkę i wynik.

## Źródła i granice tej wersji

- **Notowania:** adapter do publicznego endpointu chart Yahoo Finance, sprawdzony podczas budowy dla GPW i USA. Nie jest to kontraktowane ani gwarantowane API. Opóźnienie i pokrycie zależą od rynku; pokazujemy datę kursu, a nie deklarację „na żywo”. Pobieranie na żądanie, cache serwera 15 minut. Przy błędzie ostatnia cena zostaje zachowana. Adapter nie obchodzi logowania ani ograniczeń źródła.
- **Waluty:** oficjalne [NBP Web API](https://api.nbp.pl/), ostatnie 93 publikacje z tabeli A, cache 1 godzina. Waluty spoza tabeli A należy wycenić ręcznie. Historyczny kurs transakcji pobierany jest automatycznie z tabeli A (lub B) dla daty operacji; przy braku publikacji używamy ostatniej wcześniejszej tabeli (maksymalnie 31 dni wstecz). Formularz pokazuje datę i numer tabeli oraz źródło. Można wpisać kurs ręcznie; nie używamy bieżącego kursu zamiast historycznego.
- **Wiadomości:** publiczny kanał Yahoo Finance RSS, do 10 instrumentów i 8 nagłówków na kanał. Artykuły otwierają się u wydawcy. Brak sztucznego generowania newsów. Kanał może zawierać informacje szersze niż jedna spółka.
- **ESPI / EBI:** odnośnik do [PAP Biznes](https://biznes.pap.pl/espi) i możliwość przypisania strony raportów oraz relacji inwestorskich spółki. **Automatyczny import ESPI / EBI nie jest zaimplementowany.** Nie przedstawiamy linków jako automatycznego feedu. Integracja wymaga dostępnego, legalnie udostępnionego kanału źródłowego; nie znaleziono w tej sesji gwarantowanego bezpłatnego API.
- **Profile spółek:** opis zapisujesz w karcie instrumentu. Adresy IR i raportów uzupełnia adapter opisany poniżej. Źródło notowań dostarcza ceny, zakres 52 tygodni i wolumen, jeśli są dostępne. P/E oraz kalendarze wyników nie są podłączone. EPS z obsługiwanych rocznych raportów SEC jest dostępny w Analizach.
- **Wykres portfela:** korzysta tylko z faktycznie dostępnych cen i historycznych kursów FX. Nie ekstrapoluje dzisiejszej ceny ani FX wstecz. Brak odpowiedniej historii daje mniej punktów lub brak wykresu. Jest to wykres wartości wraz z wpłatami netto, nie TWR/XIRR. Procent wyniku = (wartość - wpłaty netto) / wpłaty netto, o ile wpłaty netto są dodatnie. Przy brakujących kursach sumy są częściowe i oznaczone. Nie stosować ich do oceny pełnego wyniku.
- **Zakres instrumentów:** dowolny instrument może być monitorowany ręcznie. Model zakup/sprzedaż opisuje jednostki wyceniane kwotowo; obligacje wpisuj w cenie pieniężnej za jednostkę, z odsetkami jako osobną operacją. Nie ma automatycznej amortyzacji, odsetek narosłych, kontraktów z mnożnikiem, depozytu zabezpieczającego, shortów, splitów, praw poboru ani rozliczeń podatkowych. Zmiana liczby jednostek po splicie wymaga korekty danych; nie pobieramy jej automatycznie.
- **Cena / FX:** zmiana ceny otwartej pozycji liczona przy dzisiejszym FX, wpływ FX = koszt walutowy pozostałych jednostek × dzisiejszy FX - historyczny koszt PLN. Suma obu daje niezrealizowany wynik. Wynik całego portfela uwzględnia także bieżącą wycenę gotówki, dywidendy i opłaty.
- Jeden portfel, bez oddzielnych rachunków IKE/IKZE. API i kod są gotowe do rozbudowy, ale ta funkcja wymaga dodania identyfikatorów rachunków i ekranów.

## Historia CSV

Import w szczegółach instrumentu, przycisk „Historia CSV”. Akceptowane są kolumny `Date,Close` lub `Data;Cena`; dodatkowe kolumny Open/High/Low/Volume są ignorowane. Separator `,` lub `;`, format dat ISO, maks. 5000 notowań. Import ustawia aktywną historię i ostatnią cenę z pliku. Poprzednia seria pozostaje w archiwum, dostępnym w szczegółach instrumentu i kopii JSON. Nie łączymy jej automatycznie z cenami o niepotwierdzonych korektach splitów. Ceny zerowe są dopuszczalne. To import **cen**, nie transakcji.

```csv
Date,Close
2026-10-02,250.50
2026-10-05,253.00
2026-10-06,260.70
```

## Weryfikacja

```sh
node tests/core.test.cjs
python3 tests/api_test.py
php -l public/api.php
```

Testy rdzenia sprawdzają m.in. FIFO, częściową sprzedaż, FX, prowizje, przepływy i brak fałszywej historii. Testy API używają tymczasowej bazy poza public i sprawdzają logowanie, CSRF, walidację, trwałość oraz konflikt wersji. Testy sieciowe źródeł są osobnymi sprawdzeniami i mogą zależeć od dostępu do internetu.

## Wdrożenie stock.mateuszrak.com

Serwer dhosting, PHP 8.4, HTTPS. Dokumenty aplikacji w `~/stock.mateuszrak.com/public_html/`. Konfiguracja `config.php` i SQLite `storage/portfolio.sqlite` są obok `public_html`, poza dostępem HTTP. Wdrożenie FTPS używa wydzielonego konta tej subdomeny, weryfikacji certyfikatu TLS i ponownego użycia sesji TLS dla kanału danych. Skrypt `scripts/deploy_stock.py` przesyła pliki do katalogu wydania, sprawdza ich SHA-256 po odczycie z FTP, następnie atomowo podmienia katalog publiczny. Poprzedni katalog zachowuje poza webroot. Nie modyfikuje pozostałych stron na koncie. Nie zastępuje istniejącej konfiguracji ani bazy.

Pierwszy dostęp: login `digitalismundus`; wygenerowane losowe hasło tymczasowe znajduje się w lokalnym pliku `DOSTEP-STOCK.txt` (uprawnienia 600, wyłączony z Git i paczki ZIP). Pierwsze logowanie wymaga zmiany hasła (14–72 bajty). Dane portfela pozostają niedostępne do zakończenia zmiany. Hasło startowe nie działa po zmianie.

### Bezpłatne 2FA TOTP

Po zalogowaniu: **Ustawienia → Bezpieczeństwo konta → Włącz 2FA**. Potwierdź obecnym hasłem. Jeśli masz już klucz bezpieczeństwa, potwierdź nim konfigurację lub podaj jednorazowy kod odzyskiwania. Przy aktywnym Duo wymagane jest świeże potwierdzenie MFA (do 5 minut); w razie potrzeby zaloguj się ponownie. Dodaj konto ręcznie w aplikacji uwierzytelniającej: nazwa STOCK, podany klucz, TOTP/SHA-1, 6 cyfr, 30 sekund. Potwierdź kodem z telefonu. Zapisz 10 jednorazowych kodów odzyskiwania; są pokazywane tylko raz i można użyć ich w polu kodu logowania. Konfiguracja wygasa po 10 minutach. Serwer akceptuje różnicę zegara do jednego interwału i odrzuca ponownie użyty kod. Sekret jest szyfrowany AES-256-GCM kluczem z prywatnego config.php, a kody odzyskiwania przechowywane wyłącznie jako skróty. Nie używamy zewnętrznego generatora QR ani płatnej usługi.

Zmiana hasła przy włączonym 2FA wymaga kodu. Wyłączenie 2FA wymaga hasła i kodu lub kodu odzyskiwania. Nie ma automatycznego resetu przez e-mail; utrata hasła oraz wszystkich czynników wymaga kontrolowanego resetu na własnym serwerze. Zachowaj kopię prywatnego `config.php` wraz ze spójną kopią bazy, ponieważ bez `auth_key` nie odczytasz sekretu TOTP.

WebAuthn/FIDO2 i U2F są obsługiwane dla fizycznych kluczy bezpieczeństwa. Obecna wersja obsługuje hasło + klucz lub hasło + TOTP / jednorazowy kod odzyskiwania. Rejestrujemy klucze zewnętrzne; systemowe i synchronizowane passkeys nie są celem tego wydania.

Automatyczne wdrożenie: workflow `.github/workflows/deploy-dhosting.yml` w repozytorium `digitalismundus/stock` uruchamia testy i publikuje poprawki po zmianie `main` lub ręcznym uruchomieniu w GitHub Actions. Korzysta z sekretów `STOCK_FTP_USER` i `STOCK_FTP_PASSWORD` oraz opcjonalnych zmiennych `STOCK_FTP_HOST` i `STOCK_FTP_PORT`. Dane są zapisane w ustawieniach Actions; nie są potrzebne lokalnie. Wydzielone konto musi wskazywać katalog `~/stock.mateuszrak.com/`, zawierający istniejące `public_html`, `config.php` i `storage`. Konfiguracja i baza pozostają na serwerze i nie trafiają do repozytorium ani logów.

Ponowne wdrożenie lokalne: ustaw prywatnie `STOCK_FTP_USER` i `STOCK_FTP_PASSWORD` (opcjonalnie `STOCK_FTP_HOST` i `STOCK_FTP_PORT`), uruchom `python3 scripts/deploy_audit.py`. Lokalny `private-deploy/config.php` jest opcjonalny; jeśli istnieje, skrypt dodatkowo sprawdza jego zgodność z serwerem. Nie wpisuj hasła w poleceniu zapisywanym do historii ani nie commituj danych dostępowych. `python3 scripts/deploy_stock.py --check` sprawdza paczkę bez połączenia.

Wdrożenie poprawek audytu: `python3 scripts/deploy_audit.py`. Skrypt sprawdza zgodność aktualnego portfela z nową walidacją bez zwracania jego treści, tworzy prywatną kopię SQLite, publikuje cały katalog public i porównuje odciski portfela, konta oraz konfiguracji. Następnie uruchamia anonimową kontrolę publicznego wydania. Nieudana kontrola przywraca poprzedni katalog strony, zachowując odrzucone wydanie poza webroot; baza i konfiguracja nie są przywracane ani nadpisywane. Sprawdzenie mechanizmu na danych syntetycznych: `python3 tests/deploy_audit_test.py`.

## Klucz Yubico Security Key C NFC

Otwórz **Ustawienia → Bezpieczeństwo konta → Dodaj klucz bezpieczeństwa**. Wpisz nazwę i aktualne hasło. Jeśli TOTP jest aktywne, potwierdź też kodem. Kliknij „Zarejestruj klucz”, w oknie przeglądarki wybierz fizyczny klucz bezpieczeństwa i potwierdź dotknięciem USB-C lub przez NFC na zgodnym urządzeniu. Przeglądarka może poprosić o PIN FIDO2. Rejestrację wykonuje właściciel klucza; aplikacja nie resetuje ani nie modyfikuje klucza. Bez TOTP pierwszy dodany klucz generuje 10 jednorazowych kodów odzyskiwania. Przy aktywnym TOTP pozostają dotychczasowe kody.

Przy logowaniu wpisz login i hasło, pole kodu pozostaw puste. Po sprawdzeniu hasła przeglądarka poprosi o klucz. TOTP lub kod odzyskiwania można wpisać jako alternatywę. Sekret prywatny pozostaje na kluczu; serwer zapisuje klucz publiczny, identyfikator, licznik podpisów i daty. Obsługujemy wiele kluczy, blokadę użycia hasła bez drugiego czynnika po przypisaniu klucza, jednorazowe wyzwania z ważnością 120 sekund, dokładną domenę `https://stock.mateuszrak.com`, sprawdzenie RP ID, podpisu i obecności użytkownika. Użyty kod / wyzwanie jest odrzucany. Usunięcie klucza wymaga aktualnego hasła i TOTP albo niedawnego logowania kluczem; zmiana wylogowuje inne sesje. Klucze z licznikiem 0 są zgodne ze standardem i nadal chronione jednorazowym wyzwaniem.

Security Key C NFC obsługuje FIDO2/U2F, a nie przechowywanie kodów OATH/TOTP. Do użycia z tą stroną nie potrzeba aplikacji Yubico Authenticator. Na iPhonie użyj aktualnego Safari i NFC lub wspieranego połączenia USB-C; dostępność sposobu połączenia zależy od urządzenia i przeglądarki.

## Katalog instrumentów

**Pozycje → Dodaj instrument** otwiera wyszukiwanie po nazwie lub tickerze. Pobieramy listę z publicznego wyszukiwania Yahoo Finance (GPW, USA, inne rynki, ETF-y, fundusze, krypto). Katalog wyników przechowujemy w prywatnej SQLite i odświeżamy przy zapytaniu; cache wyszukiwania ma 15 minut. Jest to katalog wyszukiwanych instrumentów, a nie pełny eksport wszystkich światowych giełd. Publiczny endpoint nie jest gwarantowaną usługą: przy błędzie pokazujemy zapisane wyniki z oznaczeniem lub umożliwiamy ręczne dodanie.

Wybór uzupełnia nazwę, ticker, rynek i kategorię. Waluta, bieżąca cena i historia pochodzą z endpointu notowań; w razie błędu walutę trzeba wpisać ręcznie. Nie zgadujemy waluty na podstawie samej nazwy giełdy. Użytkownik zatwierdza formularz przed dodaniem do portfela. Wybranie już istniejącego symbolu otwiera jego edycję. Odświeżenie katalogu nie zmienia automatycznie instrumentów i transakcji już zapisanych w portfelu.

## ZUS, OFE, PPK i cały portfel

Widok **ZUS · OFE · PPK** pozwala dodać wiele rachunków, instytucję, notatkę i ręczne saldo PLN z datą. Aktualizacja zachowuje wcześniejsze daty; ta sama data zastępuje saldo dla tego dnia. Można również poprawić saldo z wcześniejszej daty. Sumy korzystają z najnowszego salda każdego rachunku. Kopia JSON obejmuje rachunki i historię. Starsze portfele bez pola `retirement` są obsługiwane bez utraty danych.

Widok **Cały portfel** zawiera tabelę kwot i udziałów oraz wykres kołowy akcji, ETF, pozostałych instrumentów, gotówki i ZUS/OFE/PPK. Suma jest liczona jako inwestycje + gotówka + OFE + PPK + zapis ZUS. Osobno pokazujemy aktywa bez zapisu ZUS. Rachunki emerytalne nie zmieniają zysku inwestycyjnego ani dostępnej gotówki. Przy braku kursu / ceny wycena jest oznaczona jako częściowa. Dla ujemnej gotówki suma uwzględnia saldo ujemne, a wykres i procenty pokazują strukturę dodatnich sald.

ZUS to zapis kapitału do wyliczenia przyszłej emerytury, więc nie jest prezentowany jako dostępna gotówka. Możesz wpisać konto, subkonto i kapitał początkowy oddzielnie lub jako jedną poprawnie obliczoną sumę; nie wprowadzaj jednocześnie sumy i jej części. OFE i PPK wpisuj według aktualnego zestawienia rachunku. Nie dodawaj tych samych aktywów drugi raz w instrumentach lub gotówce. Aplikacja nie loguje się automatycznie do ZUS, funduszu OFE ani operatora PPK.

Testy: `node --test tests/core.test.cjs`, `python3 tests/api_test.py`, `python3 tests/key_test.py`. Test klucza używa syntetycznego urządzenia P-256 i prawdziwych podpisów OpenSSL przeciw izolowanemu serwerowi PHP; nie rejestruje kluczy w produkcyjnym koncie.

## Analizy i automatyczne strony emitenta

„Wyloguj” znajduje się w głównej nawigacji, pod Ustawieniami. Na telefonie jest dostępne bezpośrednio w górnym pasku oraz w menu „Więcej”. Zamyka bieżącą sesję na tym urządzeniu.

Dodawanie i edycja instrumentu automatycznie sprawdzają strony IR i raportów na podstawie symbolu źródła. Adapter używa zweryfikowanego katalogu oficjalnych stron (CDR, PKO, ORLEN, PZU, KGHM, Dino oraz duże spółki amerykańskie), oficjalnego katalogu ticker–CIK SEC, a dla pozostałych spółek próbuje dopasować nazwę i ticker w Wikidata oraz odczytać faktyczny odnośnik z witryny emitenta. Niejednoznaczna tożsamość, nieobsługiwany typ instrumentu, brak linku albo niedostępna witryna pozostawiają puste pole i komunikat. Nie konstruujemy adresów z domyślnych ścieżek. Wpisane ręcznie adresy pozostają zachowane. Cache profilu: 24 godziny. Dynamiczne pobrania dopuszczają HTTPS i publiczne adresy IPv4, przypinają DNS i sprawdzają każde przekierowanie; nie wykonują kodu HTML/JS ani odczytanych instrukcji.

Dział **Analizy** ma trzy widoki:

- **Fundamentalna:** spółki z obsługiwanymi rocznymi raportami 10-K US GAAP w [SEC EDGAR XBRL](https://www.sec.gov/search-filings/edgar-application-programming-interfaces). Przychody, wynik netto i operacyjny, przepływy operacyjne, aktywa, zobowiązania, kapitał własny, gotówka i rozwodniony EPS — tylko kiedy występują standardowe tagi i zgodne okresy oraz numer raportu. Nie łączymy kwartału z rokiem, różnych walut ani różnych raportów. Marża netto i zobowiązania/aktywa powstają wyłącznie przy komplecie zgodnych danych. Domyślnie pokazujemy najnowszy pełny rok, nie TTM ani najnowszy kwartał. Sprawozdania IFRS i tagi niestandardowe nie są mapowane automatycznie. Dla CD Projekt parser pobiera zaokrąglone przychody i skonsolidowany zysk z oficjalnego komunikatu H1 w [centrum wyników emitenta](https://www.cdprojekt.com/en/investors/result-center/), z datą publikacji; zmiana formatu daje brak danych. Pozostałe spółki GPW i rynki zagraniczne bez adaptera pokazują brak danych i linki do źródeł — nie pozorne fundamenty. Cache sprawozdań: 6 godzin.
- **Techniczna:** do dwóch lat dziennych skorygowanych cen `adjclose` Yahoo Finance (nie cena z demo ani aktualna cena wpisana ręcznie). Pomijamy bieżącą datę według strefy czasowej rynku źródłowego; podajemy ostatni dostępny dzień, liczbę obserwacji, źródło, datę odczytu i podstawę obliczeń. SMA 20/50/200, RSI 14 Wildera, MACD EMA 12–26 i sygnał EMA 9, pasma Bollingera SMA 20 ± 2 odchylenia populacyjne, zmiany za 1/5/21/63/252 dostępne sesje. EMA startuje od SMA. Brakująca historia daje „Brak danych”, bez interpolacji. Obserwacje opisują wynik obliczeń, bez prognoz, cen docelowych i rekomendacji. Korekty cen dostawcy nie zmieniają jednostek ani operacji w portfelu. Cache: 15 minut. Dla plików lokalnych / instrumentu bez symbolu źródła używa się zapisanej historii z wyraźnym opisem braku potwierdzonych korekt.

Przycisk „Odśwież dane” ponawia odczyt przez serwer; ważny cache źródła pozostaje respektowany. W trybie demo analizy na serwerze pobierają realne źródła dla wybranego tickera, co jest jawnie oznaczone; portfel nadal zawiera wyłącznie przykładowe kwoty.

Dodatkowe testy:

```sh
node --test tests/analysis.test.cjs tests/core.test.cjs
php tests/research_test.php
python3 tests/api_test.py
python3 tests/key_test.py
```

### Rekomendacje domów maklerskich

Trzeci widok Analizy → Rekomendacje DM pobiera [publiczną listę i historię DM BOŚ](https://bossa.pl/analizy/rekomendacje) oraz [bieżącą publiczną stronę raportów DM BDM](https://www.bdm.pl/analizy-i-informacje/analizy/rekomendacje). Zakres obejmuje GPW, bez płatnego źródła, logowania do DM ani obchodzenia blokad. Dla zagranicznych spółek i innych typów instrumentów podajemy brak podłączonych indywidualnych rekomendacji. Wyszukiwanie i przełącznik „Zestawienie GPW” pozwalają przeglądać także spółki spoza portfela. Cache źródeł i historii: godzina, odświeżany przy kolejnym odczycie.

Każdy wpis zawiera DM, spółkę i ticker, datę raportu, oryginalną ocenę, cenę docelową w PLN, historyczny kurs z dnia wydania i autora, jeśli występują w źródle, odnośnik do listy wydawcy oraz dostępnego pełnego PDF. DM BOŚ rozdziela ocenę fundamentalną i relatywną; chronione wpisy pokazują stan oczekiwania, bez zdekodowanej oceny, ceny ani adresu raportu. Niepoprawne lub przyszłe daty raportu i nieznane skale są odrzucane. Historia jest oddzielona od najnowszego wpisu każdego brokera; publikacje sprzed ponad roku są oznaczone jako archiwalne, bez twierdzeń o ich obowiązywaniu.

BDM: parsujemy tylko polskie tytuły i wskazane w publicznych linkach symbole raportów, daty i oceny. Autor i cena docelowa w PDF nie są automatycznie odczytywane, więc pola mają „Brak danych”; bez zgadywania. Obsługujemy widoczną stronę ostatnich raportów, nie kompletne archiwum BDM. Nie wyliczamy konsensusu całego rynku, potencjału wzrostu względem cen demo ani własnych rekomendacji inwestycyjnych. Nieobecność na liście oznacza brak potwierdzonego wpisu w adapterach, nie brak pokrycia analitycznego.

Test parserów i stanów niedostępności: `php tests/recommendations_test.php`.

### Prowizja procentowa i kwotowa

Domyślna podpowiedź aplikacji to **0,29%** i minimum **0** w walucie operacji; ustawienia użytkownika mają pierwszeństwo. Nie jest to taryfa konkretnego DM. Formularz każdej operacji ma wybór „Procentowo / Kwotowo” oraz dwa powiązane pola. Wybrane pole jest edytowalne, drugie przelicza się automatycznie. Zakup i sprzedaż: podstawa = liczba jednostek × cena. Inne operacje: wpisana kwota przed podatkiem i opłatami (wymiana walut: kwota oddawana). Procent nie jest liczony od wartości w PLN dla operacji walutowej.

Zmiana liczby jednostek, ceny lub kwoty ponawia obliczenie z wybranej stawki albo zachowuje wybraną kwotę i przelicza jej procent. Kwota wynikająca z procentu jest zaokrąglana do 0,01 waluty; wpisana ręcznie kwota pozostaje dokładnie taka jak wpisano. Bez dodatniej podstawy nie dzielimy przez zero: pokazujemy brak przeliczenia. Użytkownik może włączyć minimum z ustawień w trybie procentowym; jeśli obowiązuje, pokazujemy też efektywny procent. Ręczny tryb kwotowy zastępuje minimum.

Starsze transakcje otwierają się w trybie kwotowym z zachowaniem zapisanej prowizji. Nowe wpisy zachowują wybrany tryb, stawkę i ewentualne minimum jako metadane; kwota `fee` nadal jest podstawą rozliczenia gotówki, FIFO i wyniku. Odczyt ani wdrożenie nie przelicza wcześniejszych transakcji. Edycja procentowej prowizji i zmiana wartości operacji przelicza prowizję zgodnie z wybraną stawką.

### Historyczny kurs waluty w transakcji

Nowa operacja w walucie obcej automatycznie uzupełnia „Kurs waluty → PLN” średnim kursem NBP z daty operacji. Zmiana daty lub waluty pobiera kurs ponownie. Dni bez tabeli (weekendy, święta i dzisiejszy dzień przed publikacją) korzystają z ostatniej wcześniejszej publikacji; jej data jest jawna. Obsługujemy tabele A i B, archiwum od 02.01.2002; brak danych lub błąd pozostawia puste pole z możliwością ręcznego wpisania. Nie zastępujemy go aktualną wyceną waluty w portfelu.

„Pobierz kurs NBP” umożliwia ponowne pobranie. Własny wpis podczas pobierania przerywa automatyczne uzupełnienie tego pola. Edycja istniejącej operacji zachowuje zapisany kurs aż do zmiany daty/waluty lub użycia przycisku. Kurs otrzymanej waluty w przewalutowaniu działa tak samo. Źródło, data i numer tabeli są zapisywane przy automatycznym kursie; ręczna korekta usuwa powiązanie ze źródłem NBP.

Endpoint `GET api.php?action=fx-history&currency=USD&date=2026-01-02` wymaga sesji; odpowiedzi mają cache 24 h dla dat historycznych i 5 min dla dzisiejszej daty. Samo pobranie nie zmienia portfela ani jego bieżących kursów. Jest to wycena według wybranego dnia operacji; nie obliczamy kursów podatkowych.

### Duo Push (Universal Prompt)

Ustawienia → Bezpieczeństwo konta → Połącz Duo Push. Wpisz Client ID (20 znaków), Client secret (40 znaków) i API hostname z aplikacji Web SDK w Duo Admin Panel oraz potwierdź hasłem STOCK w zalogowanej sesji. Łączenie Duo nie wymaga ponownego wpisania TOTP ani kodu odzyskiwania. Test przekieruje do Duo; wybierz Duo Push i zatwierdź na telefonie. Konfiguracja jest zapisywana dopiero po potwierdzeniu rzeczywistego push.

Secret jest szyfrowany AES-256-GCM kluczem `auth_key`, z osobnym kontekstem `STOCK-DUO`, w prywatnej bazie SQLite. Nie jest zwracany do przeglądarki, eksportu portfela ani logów. Konfiguracja Duo i portfel są oddzielne. Aktywacja lub wyłączenie unieważnia pozostałe sesje. Wyłączenie wymaga innej dostępnej metody 2FA.

Po aktywacji domyślne logowanie wykorzystuje Duo; można wybrać dotychczasowy YubiKey lub TOTP/kod odzyskiwania. Brak połączenia z Duo nie dopuszcza logowania samym hasłem. Stan logowania jest powiązany z sesją, użytkownikiem i wersjami konfiguracji; ważny 5 minut, jednorazowy. Oficjalny SDK weryfikuje JWT (podpis, daty, wystawcę, odbiorcę i użytkownika), aplikacja dodatkowo wymaga potwierdzonego MFA oraz świeżej daty uwierzytelnienia.

Adres powrotu: `https://stock.mateuszrak.com/duo-callback.html`. Statyczna strona powrotu wykonuje same-origin POST z CSRF, dzięki czemu główne cookie sesji pozostaje SameSite=Strict. Parametry odpowiedzi są od razu usuwane z adresu; strona nie wysyła Referer. SDK Cisco Duo 1.2.0 i firebase/php-jwt 7.2.1 są zapisane w `public/lib`, z licencjami i metadanymi źródła; minimalna wersja PHP 8.0. Połączenia TLS korzystają z dołączonego pakietu CA Cisco, z limitem 15 sekund. Nie wymaga Composer na serwerze.

Duo: konfiguracja obsługuje zwykły Duo Push oraz Verified Duo Push, także wariant Bluetooth Autofill / Proximity Verification. Nadal wymagane są podpisany token, potwierdzone MFA oraz świeże uwierzytelnienie. Błędy tokenu i MFA mają oddzielne komunikaty; prywatna tabela `duo_events` przechowuje najwyżej 20 zdarzeń zawierających tylko czas, etap i stałą kategorię błędu, bez tokenów i danych użytkownika.

Każde żądanie Duo zawiera podpisane `prompt=login`, `max_age=0` i nonce powiązany z jednorazowym state. SDK weryfikuje nonce w odpowiedzi. Znacznik `auth_time` jest normalizowany z liczby JSON lub zapisu dziesiętnego do sekund Unix; nadal odrzucamy nieprawidłowy, wcześniejszy i przyszły czas. Diagnostyka czasu przechowuje wyłącznie typ i względną różnicę ograniczoną do ±86400 sekund.

Ekran logowania pokazuje login i hasło. Pole TOTP / kodu odzyskiwania jest ukryte i rozwija się po kliknięciu „Użyj kodu 2FA”; wpisanie kodu wybiera lokalne 2FA. Po aktywacji Duo główny przycisk korzysta z Duo Push, a link „Użyj klucza U2F” pozwala wybrać YubiKey. Ukryte pole kodu jest wyłączone i nie jest wysyłane. Łączenie Duo wymaga hasła w już zalogowanej sesji oraz udanego testu push; wyłączanie Duo zachowuje dotychczasowe wymagania MFA.

### Poprawki po audycie 11.10.2026

Wycena bieżąca i wykres wybierają najnowsze dostępne obserwacje z datą nie późniejszą niż dzień wyceny. Wpisanie starszej ceny lub kursu FX uzupełnia historię. Odświeżanie NBP i zgodnego źródła Yahoo zachowuje wcześniejsze punkty; inne podstawy cen i zmiana symbolu źródła zachowują poprzednią serię w archiwum. Limit aktywnej serii i każdego archiwum wynosi 5000 dat, a liczba archiwów 20. Przekroczenie limitu zatrzymuje zmianę i nie usuwa starszych danych.

Kontrakty futures nie występują w katalogu. Instrumenty z symbolem `=F` można obserwować, lecz nowe i zmienione zakupy/sprzedaże są blokowane: model portfela nie obsługuje mnożnika ani depozytu zabezpieczającego. Dawne operacje pozostają możliwe do odczytania i usunięcia.

Procentowa prowizja jest zaokrąglana dziesiętnie do grosza, z połówkami w górę, tak samo w przeglądarce i na serwerze. Odczyt akceptuje dawne kwoty wygenerowane przez poprzednią regułę bez przepisania kosztów historycznych. Zerowa wycena, bardzo małe pozycje i identyfikator `__proto__` są obsługiwane. Wartości prowadzące do przepełnienia obliczeń są odrzucane.

Po wygaśnięciu sesji przycisk ponownego logowania zachowuje formularz operacji, instrumentu, kursu, salda emerytalnego lub ustawień. Formularz można przywrócić po wczytaniu aktualnej wersji portfela; zapis wymaga ponownego zatwierdzenia. Kopia formularza jest tymczasowo przechowywana w sesji przeglądarki również na czas przekierowania Duo. Hasła i kody MFA nie są w niej zapisywane. Jawne wylogowanie usuwa kopię. Uszkodzony lokalny JSON można pobrać przed odzyskiwaniem, a otwarcie i edycja demo zachowuje oryginalny zapis.

Testy regresji:

```sh
node --test tests/core.test.cjs tests/analysis.test.cjs tests/fx.test.cjs tests/history.test.cjs
php tests/backend_validation_test.php
python3 tests/backend_regression_test.py
```

Testy przeglądarkowe wymagają Playwright oraz Chrome: `STOCK_TEST_BROWSER_CHANNEL=chrome node --test tests/app_regressions.cjs tests/ui_audit_regressions.cjs`. Używają sztucznych danych, tymczasowych profili oraz atrap odpowiedzi API; prawdziwą obsługę PHP, SQLite, CSRF i podpisów klucza sprawdzają oddzielne testy integracyjne.
