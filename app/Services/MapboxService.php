<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MapboxService
{
    /**
     * The Mapbox API client
     *
     * @var Client
     */
    protected $client;

    /**
     * The Mapbox API access token
     *
     * @var string
     */
    protected $accessToken;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://api.mapbox.com',
            'timeout' => 10.0,
        ]);
        
        $this->accessToken = env('MAPBOX_ACCESS_TOKEN');
        
        if (!$this->accessToken) {
            Log::error('MAPBOX_ACCESS_TOKEN not set in environment variables');
        }
    }

    /**
     * Calculate distance between two points in kilometers
     *
     * @param float $lat1 First point latitude
     * @param float $lng1 First point longitude
     * @param float $lat2 Second point latitude
     * @param float $lng2 Second point longitude
     * @return float Distance in kilometers
     */
    public function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        // Use Haversine formula
        $earthRadius = 6371; // in kilometers
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng/2) * sin($dLng/2);
             
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $earthRadius * $c;
    }

    /**
     * Get travel time between two points using Mapbox Directions API
     *
     * @param float $lat1 Starting point latitude
     * @param float $lng1 Starting point longitude
     * @param float $lat2 Destination point latitude
     * @param float $lng2 Destination point longitude
     * @param string $mode Travel mode (driving, walking, cycling)
     * @return array Travel information with duration in seconds and distance in meters
     */
    public function getTravelTime(float $lat1, float $lng1, float $lat2, float $lng2, string $mode = 'driving'): array
    {
        $cacheKey = "mapbox_directions_{$lat1}_{$lng1}_{$lat2}_{$lng2}_{$mode}";
        
        // Check if we have cached results
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }
        
        try {
            $response = $this->client->get("/directions/v5/mapbox/{$mode}/{$lng1},{$lat1};{$lng2},{$lat2}", [
                'query' => [
                    'access_token' => $this->accessToken,
                    'geometries' => 'geojson',
                    'overview' => 'full',
                ]
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            if (isset($data['routes']) && !empty($data['routes'])) {
                $result = [
                    'duration' => $data['routes'][0]['duration'],  // seconds
                    'distance' => $data['routes'][0]['distance'],  // meters
                    'success' => true
                ];
                
                // Cache for 24 hours
                Cache::put($cacheKey, $result, 60 * 60 * 24);
                
                return $result;
            }
            
            return [
                'duration' => null,
                'distance' => null,
                'success' => false
            ];
            
        } catch (\Exception $e) {
            Log::error('Mapbox directions API error: ' . $e->getMessage());
            
            // Fall back to distance calculation
            $distanceKm = $this->calculateDistance($lat1, $lng1, $lat2, $lng2);
            
            return [
                'duration' => $distanceKm * 120, // Rough estimate: 30 km/h average speed
                'distance' => $distanceKm * 1000, // Convert to meters
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get geocoding information for an address
     *
     * @param string $address The address to geocode
     * @return array The geocoding result with coordinates
     */
    public function geocodeAddress(string $address): array
    {
        $cacheKey = "mapbox_geocode_" . md5($address);
        
        // Check if we have cached results
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }
        
        try {
            $encodedAddress = urlencode($address);
            
            $response = $this->client->get("/geocoding/v5/mapbox.places/{$encodedAddress}.json", [
                'query' => [
                    'access_token' => $this->accessToken,
                    'limit' => 1,
                    'country' => 'br', // Limit to Brazil
                ]
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            if (isset($data['features']) && !empty($data['features'])) {
                $feature = $data['features'][0];
                $result = [
                    'latitude' => $feature['center'][1],
                    'longitude' => $feature['center'][0],
                    'full_address' => $feature['place_name'],
                    'success' => true
                ];
                
                // Cache for 30 days (addresses don't change often)
                Cache::put($cacheKey, $result, 60 * 60 * 24 * 30);
                
                return $result;
            }
            
            return [
                'latitude' => null,
                'longitude' => null,
                'full_address' => null,
                'success' => false
            ];
            
        } catch (\Exception $e) {
            Log::error('Mapbox geocoding API error: ' . $e->getMessage());
            
            return [
                'latitude' => null,
                'longitude' => null,
                'full_address' => null,
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Find providers (clinics or professionals) within a certain radius of a location,
     * sorted by distance and (optionally) price
     *
     * @param float $patientLat Patient latitude
     * @param float $patientLng Patient longitude
     * @param array $providers Array of provider objects with lat, lng, and price properties
     * @param float $maxDistanceKm Maximum distance in kilometers
     * @param bool $considerPrice Whether to factor price into the ranking
     * @return array Sorted providers with added distance information
     */
    public function findNearestProviders(float $patientLat, float $patientLng, array $providers, float $maxDistanceKm = 20, bool $considerPrice = true): array
    {
        if (empty($providers)) {
            return [];
        }
        
        $results = [];
        
        foreach ($providers as $provider) {
            // Skip providers without location data
            if (!isset($provider['latitude']) || !isset($provider['longitude']) || 
                $provider['latitude'] === null || $provider['longitude'] === null) {
                continue;
            }
            
            $distance = $this->calculateDistance(
                $patientLat, 
                $patientLng, 
                $provider['latitude'], 
                $provider['longitude']
            );
            
            // Skip if beyond max distance
            if ($distance > $maxDistanceKm) {
                continue;
            }
            
            // Add distance to provider data
            $provider['distance_km'] = $distance;
            
            // Calculate a score based on distance and price
            if ($considerPrice && isset($provider['price']) && $provider['price'] > 0) {
                // Lower score is better (weighted 70% by distance, 30% by price)
                $maxPrice = 1000; // Arbitrary max price for normalization
                $normalizedDistance = $distance / $maxDistanceKm;
                $normalizedPrice = min($provider['price'], $maxPrice) / $maxPrice;
                
                $provider['score'] = ($normalizedDistance * 0.7) + ($normalizedPrice * 0.3);
            } else {
                // Just use distance as score
                $provider['score'] = $distance / $maxDistanceKm;
            }
            
            $results[] = $provider;
        }
        
        // Sort by score (lowest first)
        usort($results, function ($a, $b) {
            return $a['score'] <=> $b['score'];
        });
        
        return $results;
    }
} 