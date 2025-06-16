# Proxy Rotator Service

A proxy rotation service built in Go, designed to work seamlessly with the Product Scraper application.

## Features

- **Smart Proxy Rotation**: Automatically switches between proxies to avoid rate limiting
- **Health Monitoring**: Continuous health checks and automatic failure detection
- **Auto-Recovery**: Automatically reactivates proxies after 5 minutes of inactivity
- **Failure Tracking**: Disables proxies after 3 consecutive failures
- **Real-time Stats**: Monitor proxy performance and availability
- **Secure**: Supports both public and authenticated proxies
- **RESTful API**: Easy integration with any application

## Quick Start

1. **Build the Service**
   ```bash
   # Build the main service
   go build -o proxy-rotator.exe
   ```

2. **Start the Service**
   ```bash
   # Run the service (default port: 8081)
   ./proxy-rotator.exe
   ```

3. **Add Your First Proxy**
   ```bash
   # Using curl
   curl -X POST http://localhost:8081/api/proxy/add \
     -H "Content-Type: application/json" \
     -d '{
       "host": "127.0.0.1",
       "port": 8118,
       "is_active": true
     }'
   ```

4. **Verify Setup**
   ```bash
   # Check proxy stats
   curl http://localhost:8081/api/proxy/stats
   ```

## API Endpoints

### Proxy Management

- `GET /api/proxy/next` - Get next available proxy
- `POST /api/proxy/add` - Add a new proxy
- `POST /api/proxy/remove` - Remove a proxy
- `GET /api/proxy/list` - List all proxies
- `GET /api/proxy/stats` - Get proxy statistics
- `POST /api/proxy/test` - Test a proxy's functionality

### Health & Monitoring

- `GET /health` - Service health check

## Integration with Laravel

1. **Configure Environment**
   Add to your Laravel `.env`:
   ```
   PROXY_ROTATOR_URL=http://localhost:8081
   ```

2. **Usage in Code**
   ```php
   $scraper = new ProductScraperService($client);
   $products = $scraper->scrape('https://www.amazon.com/dp/PRODUCT_ID');
   ```

## Proxy Configuration

### Adding a Proxy

```bash
curl -X POST http://localhost:8081/api/proxy/add \
  -H "Content-Type: application/json" \
  -d '{
    "host": "127.0.0.1",
    "port": 8118,
    "is_active": true,
    "username": "optional_username",
    "password": "optional_password"
  }'
```

### Testing a Proxy

```bash
curl -X POST http://localhost:8081/api/proxy/test \
  -H "Content-Type: application/json" \
  -d '{
    "host": "127.0.0.1",
    "port": 8118
  }'
```

## Development Setup

1. **Prerequisites**
   - Go 1.21 or higher
   - Git

2. **Installation**
   ```bash
   # Clone the repository
   git clone <repository-url>
   cd proxy-rotator

   # Install dependencies
   go mod tidy
   ```

3. **Building**
   ```bash
   # Build the service
   go build -o proxy-rotator.exe
   ```

## Proxy Health System

The service implements a sophisticated health system:

1. **Failure Detection**
   - Tracks failed requests per proxy
   - Disables proxy after 3 consecutive failures
   - Logs all failures for debugging

2. **Auto-Recovery**
   - Automatically reactivates proxies after 5 minutes
   - Resets failure count on successful requests
   - Maintains proxy rotation even during failures

3. **Performance Monitoring**
   - Tracks proxy response times
   - Monitors success/failure rates
   - Provides real-time statistics

## Troubleshooting

1. **Service Won't Start**
   - Check if port 8081 is available
   - Verify Go installation
   - Check system logs

2. **Proxy Connection Issues**
   - Verify proxy is running
   - Check proxy credentials
   - Test proxy manually

3. **High Failure Rate**
   - Check proxy health
   - Verify network connectivity
   - Review proxy configuration

## Contributing

1. Fork the repository
2. Create your feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## License

MIT License - See LICENSE file for details

