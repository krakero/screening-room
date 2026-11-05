# Screening Room Mobile App

A NativePHP for Mobile v4 app with SuperNative UI architecture.

## Foundation

The mobile app foundation includes:

- **API Client** (`app/Services/Api/ScreeningRoomApi.php`): HTTP client for the Screening Room server
- **Authentication** (`app/Services/Api/Auth.php`): Sign-in, sign-out, and auth state management  
- **Screens**: Launch, SignIn, UpNext, Calendar, Search, TitleDetail, Settings
- **Layouts**: TabsLayout (4 tabs) and StackLayout (for detail views)

## Running Tests

```bash
php mobile/artisan test
```

## Launching the App

```bash
php mobile/artisan native:run
```

## Server API

The app connects to a Screening Room server's Sanctum v1 API at `/api/v1`. 

### Authentication

- **POST** `/api/v1/auth/token` - Sign in with email/password
- **DELETE** `/api/v1/auth/token` - Sign out
- **GET** `/api/v1/me` - Get current user

## Notes

UI elements like `button` and `text_input` require the mobile-ui plugin which is not currently installed. The foundation uses core elements only (`pressable`, `text`, `column`, `row`, etc.). The four screen agents will build on this foundation with their specific implementations.
