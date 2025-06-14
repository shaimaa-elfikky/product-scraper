# Test adding a proxy
Write-Host "Testing proxy service..."
Write-Host "Adding test proxy..."
$proxyData = @{
    host = "test-proxy.com"
    port = 8080
    username = "testuser"
    password = "testpass"
    is_active = $true
} | ConvertTo-Json

$response = Invoke-RestMethod -Uri "http://localhost:8080/api/proxies" -Method Post -Body $proxyData -ContentType "application/json"
Write-Host "Proxy added successfully"

# Test listing proxies
Write-Host "`nListing all proxies..."
$proxies = Invoke-RestMethod -Uri "http://localhost:8080/api/proxies" -Method Get
Write-Host "Found $($proxies.Count) proxies:"
$proxies | ForEach-Object {
    Write-Host "- $($_.host):$($_.port)"
}

# Test getting next proxy
Write-Host "`nTesting proxy rotation..."
$nextProxy = Invoke-RestMethod -Uri "http://localhost:8080/api/proxies/next" -Method Get
Write-Host "Next proxy: $($nextProxy.host):$($nextProxy.port)"

Write-Host "`nAll tests completed successfully!" 