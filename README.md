# FleetLink Magazyn

**System zarządzania urządzeniami GPS do lokalizacji pojazdów**

Kompleksowa aplikacja webowa PHP/MySQL do zarządzania urządzeniami GPS — od magazynu przez montaż, serwis, po tworzenie ofert z umowami i protokołami.

---

## Funkcje aplikacji

### 🔧 Zarządzanie urządzeniami
- Lista producentów i modeli urządzeń GPS
- Ewidencja indywidualnych urządzeń (nr seryjny, IMEI, SIM)
- Historia montaży i serwisów dla każdego urządzenia
- Cennik zakupu/sprzedaży/montażu/abonamentu per model

### 📦 Stany magazynowe
- Aktualne stany magazynowe per model
- Przyjęcia, wydania i korekty stanów
- Historia ruchów magazynowych
- Alerty o niskim stanie magazynowym

### 🚗 Montaże i demontaże
- Rejestracja montażu urządzenia GPS w pojeździe
- Rejestracja demontażu z datą i potwierdzeniem
- Podgląd aktywnych montaży
- Powiązanie z klientem i technikiem

### 🔨 Serwisy
- Planowanie serwisów (przegląd, naprawa, wymiana, aktualizacja)
- Śledzenie statusu serwisów
- Koszty serwisów
- Powiązanie z montażem i technikiem

### 📅 Kalendarz
- Wizualizacja serwisów i montaży na kalendarzu (FullCalendar)
- Widoki: miesiąc, tydzień, lista
- Oznaczenie kolorami według statusu i typu

### 💼 CRM (Klienci i oferty)
- Baza klientów z pojazdami
- Tworzenie ofert z pozycjami i obliczaniem VAT
- Generowanie ofert do druku (PDF przez przeglądarkę)
- Umowy (montażowe, serwisowe, subskrypcyjne)
- Protokoły: przekazania (PP), uruchomienia (PU), serwisowe (PS)

### 📊 Statystyki
- Wykresy montaży i serwisów per miesiąc (Chart.js)
- Najpopularniejsze modele urządzeń
- Statystyki techników
- Wartość ofert

### 📧 E-mail
- Wysyłanie wiadomości bezpośrednio z aplikacji
- Szablony dla ofert i przypomnień
- Wsparcie PHP mail() i SMTP (SSL/TLS i STARTTLS)
- Historia wysłanych wiadomości

### 👤 Zarządzanie użytkownikami
- Role: Administrator, Technik, Użytkownik
- Zarządzanie kontami (tylko admin)

---

## Instalacja

### Wymagania
- PHP 7.4 lub nowszy (zalecane PHP 8.x)
- MySQL 5.7+ / MariaDB 10.3+
- Rozszerzenia PHP: PDO, PDO_MySQL, OpenSSL (dla SMTP)

### Kroki instalacji (hosting FTP, np. cyberfolks.pl)

1. **Pobierz pliki** i wgraj na serwer przez FTP do wybranego katalogu (np. katalog główny lub podkatalog)

2. **Upewnij się, że serwer posiada aktywną bazę MySQL** — możesz ją stworzyć w panelu hostingowym

3. **Otwórz przeglądarkę** i wejdź na adres swojej strony (np. `https://twojadomena.pl/`)

4. **Kreator instalacji uruchomi się automatycznie** (`setup.php`). Podaj:
   - Dane dostępowe do bazy MySQL (host, nazwa bazy, użytkownik, hasło)
   - Dane konta administratora
   - Opcjonalnie: konfigurację e-mail (SMTP lub PHP mail())

5. **Gotowe!** Po zakończeniu instalacji możesz zalogować się do systemu.

> ⚠️ **Bezpieczeństwo**: Po instalacji rozważ usunięcie lub zabezpieczenie pliku `setup.php`.

---

## Struktura plików

```
/
├── index.php              # Przekierowanie (login lub dashboard)
├── setup.php              # Kreator instalacji (uruchom raz)
├── login.php / logout.php # Logowanie
├── dashboard.php          # Panel główny
├── devices.php            # Urządzenia GPS
├── manufacturers.php      # Producenci
├── models.php             # Modele urządzeń
├── inventory.php          # Stan magazynowy
├── clients.php            # Klienci
├── vehicles.php           # Pojazdy
├── installations.php      # Montaże/demontaże
├── services.php           # Serwisy
├── calendar.php           # Kalendarz
├── offers.php             # Oferty
├── contracts.php          # Umowy
├── protocols.php          # Protokoły PP/PU/PS
├── statistics.php         # Statystyki
├── email.php              # Wysyłanie e-mail
├── users.php              # Zarządzanie użytkownikami
├── settings.php           # Ustawienia aplikacji
├── .htaccess              # Nagłówki bezpieczeństwa, ochrona
├── includes/
│   ├── config.php         # Konfiguracja (generowana przez setup.php)
│   ├── config.template.php # Szablon konfiguracji
│   ├── schema.sql         # Schemat bazy danych
│   ├── db.php             # Połączenie z bazą
│   ├── auth.php           # Uwierzytelnianie i sesje
│   ├── functions.php      # Funkcje pomocnicze, e-mail
│   ├── header.php         # Nagłówek HTML / nawigacja
│   ├── footer.php         # Stopka HTML
│   ├── offer_print.php    # Szablon wydruku oferty
│   └── protocol_print.php # Szablon wydruku protokołu
└── assets/
    ├── css/style.css      # Style CSS
    └── js/app.js          # JavaScript
```

---

## Bezpieczeństwo

- **Ochrona SQL Injection**: PDO z zapytaniami parametryzowanymi
- **Ochrona XSS**: `htmlspecialchars()` na wszystkich wyjściach
- **Ochrona CSRF**: Tokeny na wszystkich formularzach
- **Bezpieczeństwo sesji**: Regeneracja ID sesji, HTTPOnly cookies
- **Hasła**: bcrypt (password_hash/password_verify)
- **Blokada prób logowania**: 5 prób na 15 minut
- **Nagłówki HTTP**: X-Frame-Options, X-XSS-Protection, CSP, Referrer-Policy
- **Ochrona katalogu includes/**: `.htaccess` blokuje bezpośredni dostęp
- **RBAC**: Role administrator/technik/użytkownik

---

## Technologie

- **Backend**: PHP 7.4+
- **Baza danych**: MySQL/MariaDB
- **Frontend**: Bootstrap 5.3
- **Ikony**: Font Awesome 6
- **Kalendarz**: FullCalendar 6
- **Wykresy**: Chart.js 4

---

*FleetLink Magazyn v1.0.0*
