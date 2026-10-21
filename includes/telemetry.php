<?php
declare(strict_types=1);

/**
 * Father Gateway - High Precision Real-Time Location & Device Telemetry Engine
 * 
 * Captures GPS coordinates (within meters / house-level), reverse-geocodes to real street/house address,
 * provides IP fallback, and records historical audit trail for merchant security and anti-fraud monitoring.
 */

/**
 * Record a user's geographical location and reverse-geocode to real physical address.
 *
 * @param int         $userId   User database ID
 * @param float       $lat      Latitude (-90 to 90)
 * @param float       $lng      Longitude (-180 to 180)
 * @param float|null  $accuracy Accuracy radius in meters (e.g. 4.5m)
 * @param float|null  $altitude Altitude in meters if available
 * @param string      $source   'gps' (browser high accuracy) or 'ip'
 * @param string      $event    'login', 'register', 'dashboard', 'manual'
 * @param string|null $ip       Client IP address
 * @param string|null $device   Client Device summary string
 * @return array
 */
function record_user_location(
    int $userId,
    float $lat,
    float $lng,
    ?float $accuracy = null,
    ?float $altitude = null,
    string $source = 'gps',
    string $event = 'login',
    ?string $ip = null,
    ?string $device = null
): array {
    // Basic bounds validation
    if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
        return ['ok' => false, 'error' => 'Invalid coordinates range'];
    }

    // Skip dummy 0,0 (Null Island)
    if (abs($lat) < 0.0001 && abs($lng) < 0.0001) {
        return ['ok' => false, 'error' => 'Null coordinates'];
    }

    if ($ip === null) {
        $ip = function_exists('security_ip') ? security_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    if ($device === null) {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $dev = function_exists('parse_user_agent') ? parse_user_agent($ua) : ['summary' => 'Unknown Device'];
        $device = $dev['summary'];
    }

    // Reverse geocode to real street/house address
    $geo = reverse_geocode($lat, $lng);
    $address = $geo['display_name'] ?? ('GPS: ' . number_format($lat, 6) . ', ' . number_format($lng, 6));
    $city    = $geo['city'] ?? null;
    $state   = $geo['state'] ?? null;
    $country = $geo['country'] ?? null;

    try {
        // 1. Update user record with latest real-time coordinates & address
        $stmt = db()->prepare('
            UPDATE users SET 
                latitude = :lat,
                longitude = :lng,
                location_accuracy = :acc,
                location_address = :addr,
                location_city = :city,
                location_state = :state,
                location_country = :country,
                location_source = :source,
                location_updated_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute([
            ':lat'    => $lat,
            ':lng'    => $lng,
            ':acc'    => $accuracy,
            ':addr'   => $address,
            ':city'   => $city,
            ':state'  => $state,
            ':country'=> $country,
            ':source' => $source,
            ':id'     => $userId,
        ]);

        // 2. Insert into location history / audit trail
        $ins = db()->prepare('
            INSERT INTO user_locations 
            (user_id, latitude, longitude, accuracy, altitude, address, city, state, country, ip, device, source, event, created_at)
            VALUES 
            (:uid, :lat, :lng, :acc, :alt, :addr, :city, :state, :country, :ip, :device, :source, :event, NOW())
        ');
        $ins->execute([
            ':uid'    => $userId,
            ':lat'    => $lat,
            ':lng'    => $lng,
            ':acc'    => $accuracy,
            ':alt'    => $altitude,
            ':addr'   => $address,
            ':city'   => $city,
            ':state'  => $state,
            ':country'=> $country,
            ':ip'     => $ip,
            ':device' => $device,
            ':source' => $source,
            ':event'  => $event,
        ]);

        return [
            'ok'        => true,
            'user_id'   => $userId,
            'lat'       => $lat,
            'lng'       => $lng,
            'accuracy'  => $accuracy,
            'address'   => $address,
            'city'      => $city,
            'state'     => $state,
            'country'   => $country,
            'source'    => $source,
        ];
    } catch (\Throwable $e) {
        error_log('record_user_location failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Record explicit user-granted device GPS permission and precise coordinates.
 * Updates permission_latitude, permission_longitude, permission_accuracy, permission_address, permission_gps_at, permission_gps_granted = 1
 * as well as the active location on the users table and user_locations log.
 *
 * @param int $userId
 * @param float $lat
 * @param float $lng
 * @param float|null $accuracy
 * @param float|null $altitude
 * @param string|null $ip
 * @param string|null $device
 * @return array
 */
function record_user_permission_gps(
    int $userId,
    float $lat,
    float $lng,
    ?float $accuracy = null,
    ?float $altitude = null,
    ?string $ip = null,
    ?string $device = null
): array {
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return ['ok' => false, 'error' => 'Invalid GPS coordinates'];
    }

    $ip = $ip ?: (function_exists('security_ip') ? security_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
    $device = $device ?: ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

    // Reverse geocode to get physical address details
    $geo = reverse_geocode($lat, $lng);
    $address = $geo['display_name'] ?? null;
    $city    = $geo['city'] ?? null;
    $state   = $geo['state'] ?? null;
    $country = $geo['country'] ?? null;
    $pincode = $geo['postcode'] ?? null;

    try {
        $stmt = db()->prepare('
            UPDATE users SET 
                permission_latitude = :lat,
                permission_longitude = :lng,
                permission_accuracy = :acc,
                permission_altitude = :alt,
                permission_address = :addr,
                permission_city = :city,
                permission_state = :state,
                permission_country = :country,
                permission_pincode = :pincode,
                permission_gps_at = NOW(),
                permission_gps_granted = 1,
                latitude = :lat,
                longitude = :lng,
                location_accuracy = :acc,
                location_address = :addr,
                location_city = :city,
                location_state = :state,
                location_country = :country,
                location_source = "gps_permission",
                location_updated_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute([
            ':lat'     => $lat,
            ':lng'     => $lng,
            ':acc'     => $accuracy,
            ':alt'     => $altitude,
            ':addr'    => $address,
            ':city'    => $city,
            ':state'   => $state,
            ':country' => $country,
            ':pincode' => $pincode,
            ':id'      => $userId,
        ]);

        // Insert into audit trail
        $ins = db()->prepare('
            INSERT INTO user_locations 
            (user_id, latitude, longitude, accuracy, altitude, address, city, state, country, ip, device, source, event, created_at)
            VALUES 
            (:uid, :lat, :lng, :acc, :alt, :addr, :city, :state, :country, :ip, :device, "gps_permission", "user_permission_granted", NOW())
        ');
        $ins->execute([
            ':uid'    => $userId,
            ':lat'    => $lat,
            ':lng'    => $lng,
            ':acc'    => $accuracy,
            ':alt'    => $altitude,
            ':addr'   => $address,
            ':city'   => $city,
            ':state'  => $state,
            ':country'=> $country,
            ':ip'     => $ip,
            ':device' => $device,
        ]);

        return [
            'ok'        => true,
            'user_id'   => $userId,
            'lat'       => $lat,
            'lng'       => $lng,
            'accuracy'  => $accuracy,
            'altitude'  => $altitude,
            'address'   => $address,
            'city'      => $city,
            'state'     => $state,
            'country'   => $country,
            'pincode'   => $pincode,
            'granted'   => 1,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    } catch (\Throwable $e) {
        error_log('record_user_permission_gps failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}


/**
 * Reverse geocode Latitude and Longitude to physical address via OpenStreetMap Nominatim.
 *
 * @param float $lat
 * @param float $lng
 * @return array
 */
function reverse_geocode(float $lat, float $lng): array
{
    $cacheKey = sprintf('geo_%.5f_%.5f', $lat, $lng);
    $cacheDir = APP_ROOT . '/storage/cache/geo';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $cacheFile = $cacheDir . '/' . md5($cacheKey) . '.json';

    // 7 days local cache for identical coordinates
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400 * 7)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $url = sprintf(
        'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=%.7f&lon=%.7f&zoom=18&addressdetails=1',
        $lat,
        $lng
    );

    $opts = [
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: FatherGatewayApp/2.0 (contact@fathergateway.in)\r\nAccept-Language: en\r\n",
            'timeout' => 4.0,
        ],
    ];

    $ctx = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return [
            'display_name' => sprintf('Coordinates: %.6f, %.6f', $lat, $lng),
            'city'         => null,
            'state'        => null,
            'country'      => null,
        ];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [
            'display_name' => sprintf('Coordinates: %.6f, %.6f', $lat, $lng),
            'city'         => null,
            'state'        => null,
            'country'      => null,
        ];
    }

    $addr = $data['address'] ?? [];
    $city = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['suburb'] ?? $addr['county'] ?? null;
    $state = $addr['state'] ?? $addr['region'] ?? null;
    $country = $addr['country'] ?? null;
    $displayName = (string) ($data['display_name'] ?? '');

    $result = [
        'display_name' => $displayName,
        'city'         => $city,
        'state'        => $state,
        'country'      => $country,
        'postcode'     => $addr['postcode'] ?? null,
        'raw'          => $addr,
    ];

    @file_put_contents($cacheFile, json_encode($result));
    return $result;
}

/**
 * Fallback to IP geolocation when browser GPS is blocked or not available.
 *
 * @param string $ip
 * @return array|null
 */
function lookup_ip_location(string $ip): ?array
{
    // Check if IP is loopback or private subnet
    $isPrivate = (
        $ip === '127.0.0.1' ||
        $ip === '::1' ||
        $ip === 'localhost' ||
        str_starts_with($ip, '192.168.') ||
        str_starts_with($ip, '10.') ||
        preg_match('#^172\.(1[6-9]|2[0-9]|3[0-1])\.#', $ip)
    );

    $cacheDir = APP_ROOT . '/storage/cache/ip_geo';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $cacheKey = $isPrivate ? 'local_wan_ip' : $ip;
    $cacheFile = $cacheDir . '/' . md5($cacheKey) . '.json';

    // 1 hour cache for local WAN, 3 days for static public IPs
    $maxAge = $isPrivate ? 3600 : (86400 * 3);
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $maxAge)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['lat']) && !empty($cached['lng'])) {
            return $cached;
        }
    }

    $url = $isPrivate
        ? 'http://ip-api.com/json/?fields=status,country,regionName,city,zip,lat,lon,isp,query'
        : 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,regionName,city,zip,lat,lon,isp,query';

    $opts = [
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: FatherGatewayApp/2.0\r\n",
            'timeout' => 4.0,
        ],
    ];

    $raw = @file_get_contents($url, false, stream_context_create($opts));
    if ($raw) {
        $data = json_decode($raw, true);
        if (is_array($data) && ($data['status'] ?? '') === 'success' && !empty($data['lat']) && !empty($data['lon'])) {
            $addrParts = array_filter([$data['city'] ?? '', $data['regionName'] ?? '', $data['country'] ?? '']);
            $res = [
                'lat'      => (float) $data['lat'],
                'lng'      => (float) $data['lon'],
                'city'     => (string) ($data['city'] ?? ''),
                'state'    => (string) ($data['regionName'] ?? ''),
                'country'  => (string) ($data['country'] ?? ''),
                'postcode' => (string) ($data['zip'] ?? ''),
                'accuracy' => 5000.0, // 5km circle for IP
                'source'   => 'ip',
                'ip'       => (string) ($data['query'] ?? $ip),
                'isp'      => (string) ($data['isp'] ?? ''),
                'address'  => implode(', ', $addrParts) ?: sprintf('Coordinates: %.4f, %.4f', $data['lat'], $data['lon']),
            ];
            @file_put_contents($cacheFile, json_encode($res));
            return $res;
        }
    }

    // Fallback: ipapi.co
    $altUrl = $isPrivate ? 'https://ipapi.co/json/' : 'https://ipapi.co/' . urlencode($ip) . '/json/';
    $altRaw = @file_get_contents($altUrl, false, stream_context_create($opts));
    if ($altRaw) {
        $altData = json_decode($altRaw, true);
        if (is_array($altData) && !empty($altData['latitude']) && !empty($altData['longitude'])) {
            $addrParts = array_filter([$altData['city'] ?? '', $altData['region'] ?? '', $altData['country_name'] ?? '']);
            $res = [
                'lat'      => (float) $altData['latitude'],
                'lng'      => (float) $altData['longitude'],
                'city'     => (string) ($altData['city'] ?? ''),
                'state'    => (string) ($altData['region'] ?? ''),
                'country'  => (string) ($altData['country_name'] ?? ''),
                'postcode' => (string) ($altData['postal'] ?? ''),
                'accuracy' => 5000.0,
                'source'   => 'ip',
                'ip'       => (string) ($altData['ip'] ?? $ip),
                'isp'      => (string) ($altData['org'] ?? ''),
                'address'  => implode(', ', $addrParts) ?: sprintf('Coordinates: %.4f, %.4f', $altData['latitude'], $altData['longitude']),
            ];
            @file_put_contents($cacheFile, json_encode($res));
            return $res;
        }
    }

    return null;
}

/**
 * Fetch recent location history records for a user.
 *
 * @param int $userId
 * @param int $limit
 * @return array
 */
function get_user_location_history(int $userId, int $limit = 10): array
{
    try {
        $stmt = db()->prepare('
            SELECT * FROM user_locations 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ');
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}
