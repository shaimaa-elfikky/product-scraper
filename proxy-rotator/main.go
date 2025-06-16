package main

import (
	"crypto/tls"
	"encoding/json"
	"fmt"
	"log"
	"math/rand"
	"net/http"
	"net/url"
	"sync"
	"time"
)

type Proxy struct {
	Host      string    `json:"host"`
	Port      int       `json:"port"`
	IsActive  bool      `json:"is_active"`
	LastUsed  time.Time `json:"last_used"`
	FailCount int       `json:"fail_count"`
	Username  string    `json:"username,omitempty"`
	Password  string    `json:"password,omitempty"`
}

type ProxyRotator struct {
	proxies []*Proxy
	mu      sync.RWMutex
}

func NewProxyRotator() *ProxyRotator {
	return &ProxyRotator{
		proxies: make([]*Proxy, 0),
	}
}

func (pr *ProxyRotator) AddProxy(proxy *Proxy) {
	pr.mu.Lock()
	defer pr.mu.Unlock()

	// Check if proxy already exists
	for _, p := range pr.proxies {
		if p.Host == proxy.Host && p.Port == proxy.Port {
			log.Printf("Proxy %s:%d already exists, updating...", proxy.Host, proxy.Port)
			p.Username = proxy.Username
			p.Password = proxy.Password
			p.IsActive = true
			p.FailCount = 0
			return
		}
	}

	// Set default active status
	proxy.IsActive = true
	proxy.FailCount = 0
	proxy.LastUsed = time.Time{}

	pr.proxies = append(pr.proxies, proxy)
	log.Printf("Added proxy %s:%d (Total: %d)", proxy.Host, proxy.Port, len(pr.proxies))
}

func (pr *ProxyRotator) RemoveProxy(host string, port int) {
	pr.mu.Lock()
	defer pr.mu.Unlock()

	for i, proxy := range pr.proxies {
		if proxy.Host == host && proxy.Port == port {
			pr.proxies = append(pr.proxies[:i], pr.proxies[i+1:]...)
			log.Printf("Removed proxy %s:%d (Remaining: %d)", host, port, len(pr.proxies))
			break
		}
	}
}

func (pr *ProxyRotator) GetNextProxy() *Proxy {
	pr.mu.Lock()
	defer pr.mu.Unlock()

	if len(pr.proxies) == 0 {
		log.Println("No proxies configured in rotator")
		return nil
	}

	activeProxies := make([]*Proxy, 0)
	for _, proxy := range pr.proxies {
		if proxy.IsActive {
			activeProxies = append(activeProxies, proxy)
		}
	}

	if len(activeProxies) == 0 {
		log.Printf("No active proxies available (Total: %d, All inactive)", len(pr.proxies))
		// Try to reactivate proxies that have been inactive for a while
		pr.reactivateOldProxies()
		
		// Check again after reactivation
		for _, proxy := range pr.proxies {
			if proxy.IsActive {
				activeProxies = append(activeProxies, proxy)
			}
		}
		
		if len(activeProxies) == 0 {
			return nil
		}
	}

	// Randomly select a proxy
	proxy := activeProxies[rand.Intn(len(activeProxies))]
	proxy.LastUsed = time.Now()
	log.Printf("Selected proxy %s:%d (Active: %d/%d)", proxy.Host, proxy.Port, len(activeProxies), len(pr.proxies))
	return proxy
}

// Reactivate proxies that have been inactive for more than 5 minutes
func (pr *ProxyRotator) reactivateOldProxies() {
	cutoff := time.Now().Add(-5 * time.Minute)
	reactivated := 0
	
	for _, proxy := range pr.proxies {
		if !proxy.IsActive && (proxy.LastUsed.IsZero() || proxy.LastUsed.Before(cutoff)) {
			proxy.IsActive = true
			proxy.FailCount = 0
			reactivated++
		}
	}
	
	if reactivated > 0 {
		log.Printf("Reactivated %d proxies that were inactive for >5 minutes", reactivated)
	}
}

func (pr *ProxyRotator) MarkProxyFailed(host string, port int) {
	pr.mu.Lock()
	defer pr.mu.Unlock()

	for _, proxy := range pr.proxies {
		if proxy.Host == host && proxy.Port == port {
			proxy.FailCount++
			log.Printf("Proxy %s:%d failed (count: %d)", host, port, proxy.FailCount)
			
			// Increase threshold to 3 failures (more lenient)
			if proxy.FailCount >= 3 {
				proxy.IsActive = false
				log.Printf("Proxy %s:%d marked as inactive after %d failures", host, port, proxy.FailCount)
			}
			break
		}
	}
}

func (pr *ProxyRotator) ResetFailCount(host string, port int) {
	pr.mu.Lock()
	defer pr.mu.Unlock()

	for _, proxy := range pr.proxies {
		if proxy.Host == host && proxy.Port == port {
			proxy.FailCount = 0
			proxy.IsActive = true
			log.Printf("Reset fail count for proxy %s:%d", host, port)
			break
		}
	}
}

func (pr *ProxyRotator) GetStats() map[string]interface{} {
	pr.mu.RLock()
	defer pr.mu.RUnlock()

	total := len(pr.proxies)
	active := 0
	inactive := 0

	for _, proxy := range pr.proxies {
		if proxy.IsActive {
			active++
		} else {
			inactive++
		}
	}

	return map[string]interface{}{
		"total":    total,
		"active":   active,
		"inactive": inactive,
	}
}

func testProxyWithAuth(proxy *Proxy) error {
	var proxyURL string
	if proxy.Username != "" && proxy.Password != "" {
		proxyURL = fmt.Sprintf("http://%s:%s@%s:%d",
			url.QueryEscape(proxy.Username),
			url.QueryEscape(proxy.Password),
			proxy.Host, proxy.Port)
	} else {
		proxyURL = fmt.Sprintf("http://%s:%d", proxy.Host, proxy.Port)
	}

	proxyURLParsed, err := url.Parse(proxyURL)
	if err != nil {
		return fmt.Errorf("invalid proxy URL: %v", err)
	}

	client := &http.Client{
		Transport: &http.Transport{
			Proxy: http.ProxyURL(proxyURLParsed),
			TLSClientConfig: &tls.Config{
				InsecureSkipVerify: true,
			},
		},
		Timeout: 10 * time.Second, // Reasonable timeout
	}

	// Test with multiple URLs for better reliability
	testURLs := []string{
		"http://httpbin.org/ip",
		"http://icanhazip.com",
		"http://ipecho.net/plain",
	}

	var lastErr error
	for _, testURL := range testURLs {
		req, err := http.NewRequest("GET", testURL, nil)
		if err != nil {
			lastErr = err
			continue
		}

		// Add user agent to avoid blocking
		req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36")

		resp, err := client.Do(req)
		if err != nil {
			lastErr = err
			continue
		}
		resp.Body.Close()

		if resp.StatusCode == http.StatusOK {
			return nil // Success
		}
		lastErr = fmt.Errorf("bad status: %d", resp.StatusCode)
	}

	return lastErr
}

func main() {
	rand.Seed(time.Now().UnixNano())
	rotator := NewProxyRotator()

	// Add health check endpoint
	http.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]string{"status": "ok"})
	})

	// Add stats endpoint
	http.HandleFunc("/api/proxy/stats", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Methods", "GET, OPTIONS")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type")

		if r.Method == "OPTIONS" {
			w.WriteHeader(http.StatusOK)
			return
		}

		if r.Method != "GET" {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		stats := rotator.GetStats()
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(stats)
	})

	http.HandleFunc("/api/proxy/add", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Methods", "POST, OPTIONS")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type")

		if r.Method == "OPTIONS" {
			w.WriteHeader(http.StatusOK)
			return
		}

		if r.Method != "POST" {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		var proxy Proxy
		if err := json.NewDecoder(r.Body).Decode(&proxy); err != nil {
			http.Error(w, fmt.Sprintf("Invalid JSON: %v", err), http.StatusBadRequest)
			return
		}

		// Validate proxy data
		if proxy.Host == "" || proxy.Port <= 0 {
			http.Error(w, "Invalid proxy host or port", http.StatusBadRequest)
			return
		}

		rotator.AddProxy(&proxy)
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]interface{}{
			"message": "Proxy added successfully",
			"stats":   rotator.GetStats(),
		})
	})

	http.HandleFunc("/api/proxy/remove", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Methods", "POST, OPTIONS")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type")

		if r.Method == "OPTIONS" {
			w.WriteHeader(http.StatusOK)
			return
		}

		if r.Method != "POST" {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		var proxy Proxy
		if err := json.NewDecoder(r.Body).Decode(&proxy); err != nil {
			http.Error(w, err.Error(), http.StatusBadRequest)
			return
		}

		rotator.RemoveProxy(proxy.Host, proxy.Port)
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]interface{}{
			"message": "Proxy removed successfully",
			"stats":   rotator.GetStats(),
		})
	})

	http.HandleFunc("/api/proxy/test", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Methods", "POST, OPTIONS")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type")

		if r.Method == "OPTIONS" {
			w.WriteHeader(http.StatusOK)
			return
		}

		if r.Method != "POST" {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		var proxy Proxy
		if err := json.NewDecoder(r.Body).Decode(&proxy); err != nil {
			http.Error(w, err.Error(), http.StatusBadRequest)
			return
		}

		log.Printf("Testing proxy %s:%d", proxy.Host, proxy.Port)
		if err := testProxyWithAuth(&proxy); err != nil {
			rotator.MarkProxyFailed(proxy.Host, proxy.Port)
			log.Printf("Proxy test failed for %s:%d: %v", proxy.Host, proxy.Port, err)
			w.WriteHeader(http.StatusBadGateway)
			json.NewEncoder(w).Encode(map[string]interface{}{
				"message": fmt.Sprintf("Proxy test failed: %v", err),
				"stats":   rotator.GetStats(),
			})
			return
		}

		rotator.ResetFailCount(proxy.Host, proxy.Port)
		log.Printf("Proxy test successful for %s:%d", proxy.Host, proxy.Port)
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]interface{}{
			"message": "Proxy test successful",
			"stats":   rotator.GetStats(),
		})
	})

	http.HandleFunc("/api/proxy/list", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Methods", "GET, OPTIONS")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type")

		if r.Method == "OPTIONS" {
			w.WriteHeader(http.StatusOK)
			return
		}

		if r.Method != "GET" {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		rotator.mu.RLock()
		proxies := make([]Proxy, len(rotator.proxies))
		for i, p := range rotator.proxies {
			proxies[i] = *p
		}
		rotator.mu.RUnlock()

		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]interface{}{
			"proxies": proxies,
			"stats":   rotator.GetStats(),
		})
	})

	http.HandleFunc("/api/proxy/next", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Methods", "GET, OPTIONS")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type")

		if r.Method == "OPTIONS" {
			w.WriteHeader(http.StatusOK)
			return
		}

		if r.Method != "GET" {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		proxy := rotator.GetNextProxy()
		if proxy == nil {
			stats := rotator.GetStats()
			log.Printf("No proxies available - Stats: %+v", stats)
			w.WriteHeader(http.StatusServiceUnavailable)
			json.NewEncoder(w).Encode(map[string]interface{}{
				"error":   "No proxies available",
				"message": "Please add active proxies to the rotator",
				"stats":   stats,
			})
			return
		}

		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]interface{}{
			"proxy": proxy,
			"stats": rotator.GetStats(),
		})
	})

	log.Println("Starting proxy rotator service on :8081")
	log.Println("Endpoints:")
	log.Println("  GET  /health - Health check")
	log.Println("  GET  /api/proxy/stats - Get proxy statistics")
	log.Println("  POST /api/proxy/add - Add proxy")
	log.Println("  POST /api/proxy/remove - Remove proxy")
	log.Println("  POST /api/proxy/test - Test proxy")
	log.Println("  GET  /api/proxy/list - List all proxies")
	log.Println("  GET  /api/proxy/next - Get next available proxy")
	
	log.Fatal(http.ListenAndServe(":8081", nil))
}