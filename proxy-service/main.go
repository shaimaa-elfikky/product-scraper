package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"sync"
	"time"
)

type Proxy struct {
	ID        int       `json:"id"`
	Host      string    `json:"host"`
	Port      int       `json:"port"`
	Username  string    `json:"username,omitempty"`
	Password  string    `json:"password,omitempty"`
	IsActive  bool      `json:"is_active"`
	LastUsed  time.Time `json:"last_used"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

type ProxyManager struct {
	proxies []Proxy
	mu      sync.RWMutex
	current int
	nextID  int
}

func NewProxyManager() *ProxyManager {
	return &ProxyManager{
		proxies: make([]Proxy, 0),
		current: 0,
		nextID:  1,
	}
}

func (pm *ProxyManager) AddProxy(proxy Proxy) {
	pm.mu.Lock()
	defer pm.mu.Unlock()
	
	// Assign next available ID
	proxy.ID = pm.nextID
	pm.nextID++
	
	proxy.CreatedAt = time.Now()
	proxy.UpdatedAt = time.Now()
	proxy.LastUsed = time.Now()
	pm.proxies = append(pm.proxies, proxy)
}

func (pm *ProxyManager) GetNextProxy() *Proxy {
	pm.mu.Lock()
	defer pm.mu.Unlock()

	if len(pm.proxies) == 0 {
		return nil
	}

	// Get the next proxy in rotation
	proxy := &pm.proxies[pm.current]
	proxy.LastUsed = time.Now()
	
	// Move to next proxy
	pm.current = (pm.current + 1) % len(pm.proxies)
	
	return proxy
}

func (pm *ProxyManager) ListProxies() []Proxy {
	pm.mu.RLock()
	defer pm.mu.RUnlock()
	return pm.proxies
}

func (pm *ProxyManager) RemoveProxy(id int) bool {
	pm.mu.Lock()
	defer pm.mu.Unlock()

	for i, proxy := range pm.proxies {
		if proxy.ID == id {
			pm.proxies = append(pm.proxies[:i], pm.proxies[i+1:]...)
			return true
		}
	}
	return false
}

func main() {
	proxyManager := NewProxyManager()

	// Add some test proxies
	proxyManager.AddProxy(Proxy{
		Host:     "proxy1.example.com",
		Port:     8080,
		Username: "user1",
		Password: "pass1",
		IsActive: true,
	})

	proxyManager.AddProxy(Proxy{
		Host:     "proxy2.example.com",
		Port:     8080,
		Username: "user2",
		Password: "pass2",
		IsActive: true,
	})

	// API endpoints
	http.HandleFunc("/api/proxies", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Methods", "GET, POST, DELETE, OPTIONS")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type")

		if r.Method == "OPTIONS" {
			w.WriteHeader(http.StatusOK)
			return
		}

		switch r.Method {
		case "GET":
			proxies := proxyManager.ListProxies()
			json.NewEncoder(w).Encode(proxies)
		case "POST":
			var proxy Proxy
			if err := json.NewDecoder(r.Body).Decode(&proxy); err != nil {
				http.Error(w, err.Error(), http.StatusBadRequest)
				return
			}
			proxyManager.AddProxy(proxy)
			w.WriteHeader(http.StatusCreated)
		case "DELETE":
			id := r.URL.Query().Get("id")
			if id == "" {
				http.Error(w, "ID is required", http.StatusBadRequest)
				return
			}
			var idInt int
			if _, err := fmt.Sscanf(id, "%d", &idInt); err != nil {
				http.Error(w, "Invalid ID", http.StatusBadRequest)
				return
			}
			if proxyManager.RemoveProxy(idInt) {
				w.WriteHeader(http.StatusOK)
			} else {
				http.Error(w, "Proxy not found", http.StatusNotFound)
			}
		default:
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		}
	})

	http.HandleFunc("/api/proxies/next", func(w http.ResponseWriter, r *http.Request) {
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

		proxy := proxyManager.GetNextProxy()
		if proxy == nil {
			http.Error(w, "No proxies available", http.StatusNotFound)
			return
		}

		json.NewEncoder(w).Encode(proxy)
	})

	log.Println("Starting proxy management service on :8081")
	log.Fatal(http.ListenAndServe(":8081", nil))
} 