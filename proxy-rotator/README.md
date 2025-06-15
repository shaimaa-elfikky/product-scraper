# Proxy Rotator Service



## What's Inside?

- Smart Rotation: Automatically switches between proxies
- Health Checks: Keeps track of which proxies are working
- Auto-Block: Disables bad proxies after 3 failures
- Stats: See how your proxies are performing
- Easy CLI: Manage everything from your terminal
- Secure: Works with both public and private proxies

## Quick Start

1. Start the Service
```bash
# Run this in one terminal
./proxy-rotator.exe
```

2. Add Your First Proxy
```bash
# Run this in another terminal
./proxy-manager.exe add 127.0.0.1 8080
```

3. Check Your Proxies
```bash
./proxy-manager.exe list
```

That's it! Your proxy rotator is up and running.

## Using the CLI Tool

The CLI tool makes managing proxies super easy:

```bash
# Add a new proxy
./proxy-manager.exe add 127.0.0.1 8080

# Add a proxy with username/password
./proxy-manager.exe add 127.0.0.1 8080 myuser mypass

# See all your proxies
./proxy-manager.exe list

# Get the next proxy to use
./proxy-manager.exe next

# Test if a proxy works
./proxy-manager.exe test 127.0.0.1 8080

# Need help?
./proxy-manager.exe help
```

## Using with Laravel

1. Add this to your `.env`:
```
PROXY_ROTATOR_URL=http://localhost:8081
```

2. Use it in your code:
```php
$scraper = new ProductScraperService($client);
$products = $scraper->scrape('https://www.amazon.com/dp/PRODUCT_ID');
```

The service will automatically:
- Pick the best proxy
- Track failures
- Switch proxies when needed

## API Endpoints

Need to integrate with something else? Here are the available endpoints:

- GET /api/proxy/next - Get your next proxy
- POST /api/proxy/add - Add a new proxy
- GET /api/proxy/list - See all your proxies
- POST /api/proxy/failed - Tell us a proxy failed
- POST /api/proxy/success - Tell us a proxy worked

Example:
```bash
# Add a proxy
curl -X POST http://localhost:8081/api/proxy/add \
  -H "Content-Type: application/json" \
  -d '{
    "host": "127.0.0.1",
    "port": 8080,
    "is_active": true
  }'
```

## Development

Here's what you need:

1. Install Go (version 1.21 or higher)
2. Clone the repo
3. Install dependencies:
```bash
go mod tidy
```

4. Build it:
```bash
# Build the main service
go build -o proxy-rotator.exe

# Build the CLI tool
cd cmd/proxy-manager
go build -o proxy-manager.exe
```

