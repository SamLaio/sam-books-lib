package main

import "testing"

func TestNormalizeISBN(t *testing.T) {
	tests := map[string]string{
		"urn:isbn:9786269626697": "9786269626697",
		"978-626-96266-9-7":      "9786269626697",
		"0-306-40615-2":          "0306406152",
		"bad-value":              "bad-value",
		"":                       "",
	}

	for input, expected := range tests {
		if actual := normalizeISBN(input); actual != expected {
			t.Fatalf("normalizeISBN(%q) = %q, want %q", input, actual, expected)
		}
	}
}
