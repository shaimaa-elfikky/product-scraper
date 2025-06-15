package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io/ioutil"
	"net/http"
	"net/url"
	"os"
	"strconv"
	"time"

	"github.com/fatih/color"
	"github.com/olekukonko/tablewriter"
)

const (
	baseURL = "http://localhost:8081/api/proxy"
)

type Proxy struct {
	Host      string    `json:"host"`
	Port      int       `json:"port"`
	Username  string    `json:"username,omitempty"`
	Password  string    `json:"password,omitempty"`
	IsActive  bool      `json:"is_active"`
	LastUsed  time.Time `json:"last_used"`
	FailCount int       `json:"fail_count"`
}

func main() {
	if len(os.Args) < 2 {
		printHelp()
		return
	}

	command := os.Args[1]

	switch command {
	case "add":
		if len(os.Args) < 4 {
			fmt.Println("Usage: proxy-manager add <host> <port> [username] [password]")
			return
		}
		addProxy(os.Args[2:])
	case "list":
		listProxies()
	case "next":
		getNextProxy()
	case "test":
		if len(os.Args) < 4 {
			fmt.Println("Usage: proxy-manager test <host> <port>")
			return
		}
		testProxy(os.Args[2], os.Args[3])
	case "help":
		printHelp()
	default:
		fmt.Printf("Unknown command: %s\n", command)
		printHelp()
	}
}

func printHelp() {
	fmt.Println("Proxy Manager CLI")
	fmt.Println("Usage:")
	fmt.Println("  proxy-manager add <host> <port> [username] [password]  - Add a new proxy")
	fmt.Println("  proxy-manager list                                     - List all proxies")
	fmt.Println("  proxy-manager next                                     - Get next proxy")
	fmt.Println("  proxy-manager test <host> <port>                      - Test a proxy")
	fmt.Println("  proxy-manager help                                     - Show this help")
}

func addProxy(args []string) {
	host := args[0]
	port, err := strconv.Atoi(args[1])
	if err != nil {
		fmt.Printf("Invalid port: %s\n", args[1])
		return
	}

	proxy := Proxy{
		Host:     host,
		Port:     port,
		IsActive: true,
	}

	if len(args) > 2 {
		proxy.Username = args[2]
	}
	if len(args) > 3 {
		proxy.Password = args[3]
	}

	jsonData, err := json.Marshal(proxy)
	if err != nil {
		fmt.Printf("Error creating proxy data: %v\n", err)
		return
	}

	resp, err := http.Post(baseURL+"/add", "application/json", bytes.NewBuffer(jsonData))
	if err != nil {
		fmt.Printf("Error adding proxy: %v\n", err)
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusCreated {
		color.Green("Proxy added successfully!")
	} else {
		body, _ := ioutil.ReadAll(resp.Body)
		color.Red("Failed to add proxy: %s", string(body))
	}
}

func listProxies() {
	resp, err := http.Get(baseURL + "/list")
	if err != nil {
		fmt.Printf("Error getting proxies: %v\n", err)
		return
	}
	defer resp.Body.Close()

	var proxies []Proxy
	if err := json.NewDecoder(resp.Body).Decode(&proxies); err != nil {
		fmt.Printf("Error decoding response: %v\n", err)
		return
	}

	if len(proxies) == 0 {
		fmt.Println("No proxies found")
		return
	}

	table := tablewriter.NewWriter(os.Stdout)
	table.SetHeader([]string{"Host", "Port", "Status", "Last Used", "Fail Count"})

	for _, p := range proxies {
		status := "Active"
		if !p.IsActive {
			status = "Inactive"
		}

		lastUsed := "Never"
		if !p.LastUsed.IsZero() {
			lastUsed = p.LastUsed.Format("2006-01-02 15:04:05")
		}

		table.Append([]string{
			p.Host,
			strconv.Itoa(p.Port),
			status,
			lastUsed,
			strconv.Itoa(p.FailCount),
		})
	}

	table.Render()
}

func getNextProxy() {
	resp, err := http.Get(baseURL + "/next")
	if err != nil {
		fmt.Printf("Error getting next proxy: %v\n", err)
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusServiceUnavailable {
		color.Red("No active proxies available")
		return
	}

	var proxy Proxy
	if err := json.NewDecoder(resp.Body).Decode(&proxy); err != nil {
		fmt.Printf("Error decoding response: %v\n", err)
		return
	}

	color.Green("Next proxy:")
	fmt.Printf("Host: %s\n", proxy.Host)
	fmt.Printf("Port: %d\n", proxy.Port)
	if proxy.Username != "" {
		fmt.Printf("Username: %s\n", proxy.Username)
	}
	if proxy.Password != "" {
		fmt.Printf("Password: %s\n", proxy.Password)
	}
	fmt.Printf("Last Used: %s\n", proxy.LastUsed.Format("2006-01-02 15:04:05"))
	fmt.Printf("Fail Count: %d\n", proxy.FailCount)
}

func testProxy(host, portStr string) {
	port, err := strconv.Atoi(portStr)
	if err != nil {
		fmt.Printf("Invalid port: %s\n", portStr)
		return
	}

	proxy := Proxy{
		Host: host,
		Port: port,
	}

	jsonData, err := json.Marshal(proxy)
	if err != nil {
		fmt.Printf("Error creating test data: %v\n", err)
		return
	}

	// Test the proxy
	client := &http.Client{
		Timeout: 10 * time.Second,
		Transport: &http.Transport{
			Proxy: http.ProxyURL(&url.URL{
				Scheme: "http",
				Host:   fmt.Sprintf("%s:%d", host, port),
			}),
		},
	}

	start := time.Now()
	resp, err := client.Get("http://www.google.com")
	duration := time.Since(start)

	if err != nil {
		color.Red("Proxy test failed: %v", err)
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusOK {
		color.Green("Proxy test successful!")
		fmt.Printf("Response time: %v\n", duration)
	} else {
		color.Red("Proxy test failed with status: %d", resp.StatusCode)
	}
}
