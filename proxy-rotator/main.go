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

	// Set default active status if not specified
	if proxy.FailCount == 0 {
		proxy.IsActive = true
	}

	pr.proxies = append(pr.proxies, proxy)
}

func (pr *ProxyRotator) RemoveProxy(host string, port int) {
	pr.mu.Lock()
	defer pr.mu.Unlock()

	for i, proxy := range pr.proxies {
		if proxy.Host == host && proxy.Port == port {
			pr.proxies = append(pr.proxies[:i], pr.proxies[i+1:]...)
			break
		}
	}
}

func (pr *ProxyRotator) GetNextProxy() *Proxy {
	pr.mu.Lock()
	defer pr.mu.Unlock()

	activeProxies := make([]*Proxy, 0)
	for _, proxy := range pr.proxies {
		if proxy.IsActive {
			activeProxies = append(activeProxies, proxy)
		}
	}

	if len(activeProxies) == 0 {
		return nil
	}

	// Randomly select a proxy
	proxy := activeProxies[rand.Intn(len(activeProxies))]
	proxy.LastUsed = time.Now()
	return proxy
}

func (pr *ProxyRotator) MarkProxyFailed(host string, port int) {
	pr.mu.Lock()
	defer pr.mu.Unlock()

	for _, proxy := range pr.proxies {
		if proxy.Host == host && proxy.Port == port {
			proxy.FailCount++
			// Increase threshold to 5 failures
			if proxy.FailCount >= 5 {
				proxy.IsActive = false
				log.Printf("Proxy %s:%d marked as inactive after %d failures",
					host, port, proxy.FailCount)
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
			break
		}
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
		Timeout: 15 * time.Second, // Reduced timeout
	}

	// Use a more proxy-friendly test URL
	req, err := http.NewRequest("GET", "http://httpbin.org/ip", nil)
	if err != nil {
		return err
	}

	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("bad status: %d", resp.StatusCode)
	}

	return nil
}

func main() {
	rand.Seed(time.Now().UnixNano())
	rotator := NewProxyRotator()

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
			http.Error(w, err.Error(), http.StatusBadRequest)
			return
		}

		rotator.AddProxy(&proxy)
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]string{"message": "Proxy added successfully"})
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
		json.NewEncoder(w).Encode(map[string]string{"message": "Proxy removed successfully"})
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

		if err := testProxyWithAuth(&proxy); err != nil {
			rotator.MarkProxyFailed(proxy.Host, proxy.Port)
			http.Error(w, fmt.Sprintf("Proxy test failed: %v", err), http.StatusBadGateway)
			return
		}

		rotator.ResetFailCount(proxy.Host, proxy.Port)
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]string{"message": "Proxy test successful"})
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
		json.NewEncoder(w).Encode(proxies)
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
			http.Error(w, "No proxies available", http.StatusServiceUnavailable)
			return
		}

		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(proxy)
	})

	log.Println("Starting proxy rotator service on :8081")
	log.Fatal(http.ListenAndServe(":8081", nil))
}
