# Proxy Management Microservice

A Go-based microservice for managing and rotating proxies for web scraping.

## Features

- Proxy rotation with round-robin algorithm
- RESTful API for proxy management
- Thread-safe proxy operations
- CORS support for cross-origin requests
- Automatic proxy usage tracking

## API Endpoints

### List All Proxies
```
GET /api/proxies
```

### Add New Proxy
```
POST /api/proxies
Content-Type: application/json

{
    "host": "proxy.example.com",
    "port": 8080,
    "username": "user",
    "password": "pass",
    "is_active": true
}
```

### Remove Proxy
```
DELETE /api/proxies?id=1
```

### Get Next Proxy
```
GET /api/proxies/next
```

## Setup

1. Install Go (1.16 or later)
2. Clone the repository
3. Navigate to the proxy-service directory
4. Run the service:
```bash
go run main.go
```

The service will start on port 8080.

## Integration with Laravel

To use this proxy service with the Laravel scraper:

1. Update the `ProductScraperService` to fetch a proxy before each request
2. Add proxy configuration to the Guzzle client
3. Handle proxy rotation and error cases

Example Laravel integration:
```php
$proxyResponse = Http::get('http://localhost:8080/api/proxies/next');
$proxy = $proxyResponse->json();

$client = new Client([
    'proxy' => [
        'http'  => "http://{$proxy['username']}:{$proxy['password']}@{$proxy['host']}:{$proxy['port']}",
        'https' => "http://{$proxy['username']}:{$proxy['password']}@{$proxy['host']}:{$proxy['port']}"
    ]
]);
```

## Security Considerations

- The service includes CORS headers for development
- In production, restrict CORS to specific origins
- Consider adding authentication to the API
- Use HTTPS in production
- Store proxy credentials securely

## Future Improvements

- Add proxy health checks
- Implement proxy blacklisting for failed proxies
- Add rate limiting
- Add proxy performance metrics
- Implement proxy authentication
- Add database persistence for proxies 