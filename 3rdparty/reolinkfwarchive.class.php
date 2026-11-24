<?php

/* This file is part of Jeedom.
 *
 * Helper to check official Reolink firmware updates via the community-maintained
 * AT0myks/reolink-fw-archive index while strictly enforcing official Reolink HTTPS
 * CDN download origins (since Reolink .pak/.paks images only carry CRC32 checksums).
 */

class reolinkFwArchive {

  const RAW_BASE_URL = 'https://raw.githubusercontent.com/AT0myks/reolink-fw-archive/main/';
  const REPO_HTML_URL = 'https://github.com/AT0myks/reolink-fw-archive';
  const CACHE_TTL = 43200; // 12 hours
  const CACHE_SCHEMA_VER = 1;

  public static function getCacheFilePath() {
    if (class_exists('jeedom') && method_exists('jeedom', 'getTmpFolder')) {
      return jeedom::getTmpFolder('reolink') . '/fw_archive_index.json';
    }
    return sys_get_temp_dir() . '/jeedom_reolink_fw_archive_index.json';
  }

  /**
   * Strictly whitelist official Reolink HTTPS download hosts.
   * Rejects non-HTTPS URLs, credentials-in-URL, and third-party file hosts.
   */
  public static function isOfficialReolinkUrl($url) {
    if (!is_string($url) || trim($url) === '') {
      return false;
    }
    $parts = @parse_url(trim($url));
    if (!is_array($parts)) {
      return false;
    }
    if (strtolower($parts['scheme'] ?? '') !== 'https') {
      return false;
    }
    if (!empty($parts['user']) || !empty($parts['pass'])) {
      return false;
    }
    $host = strtolower(rtrim($parts['host'] ?? '', '.'));
    if ($host === '') {
      return false;
    }
    $exactHosts = array(
      'home-cdn.reolink.us',
      'reolink.us',
      'cdn.reolink.com',
      'reolink.com',
      'support.reolink.com',
      'support-d.reolink.com',
      'reolink.zendesk.com',
      'reolink-storage.s3.amazonaws.com',
    );
    if (in_array($host, $exactHosts, true)) {
      return true;
    }
    if (substr($host, -12) === '.reolink.com' || substr($host, -11) === '.reolink.us') {
      return true;
    }
    $path = $parts['path'] ?? '';
    if ($host === 's3.amazonaws.com' && strpos($path, '/reolink-storage/') === 0) {
      return true;
    }
    return false;
  }

  public static function cleanHw($hw) {
    $hw = strtoupper(str_replace('-', '_', trim(strval($hw))));
    return preg_replace('/[^A-Z0-9_]/', '', $hw);
  }

  public static function cleanModel($model, $stripBrand = false) {
    $s = str_replace("\xC2\xA0", ' ', trim(strval($model)));
    $s = preg_replace('/\s*\(NVR\)\s*$/i', '', $s);
    $s = preg_replace('/-5MP$/i', '', $s);
    $fwParenPos = strpos($s, "\xEF\xBC\x88"); // full-width '（'
    if ($fwParenPos !== false) {
      $s = trim(substr($s, 0, $fwParenPos));
    }
    if ($stripBrand) {
      $s = preg_replace('/^(?:reolink\s+|e\s+series\s+)/i', '', $s);
    }
    $s = preg_replace('/[\s_-]+/', '-', strtolower($s));
    return trim($s, '-');
  }

  /**
   * Parse a Reolink firmware version string such as:
   * - "v3.1.0.4885_25051308_v1.0.0.146"
   * - "v3.2.0.4858_2508273415"
   * - "v2.0.0.4265_1489"
   */
  public static function parseFirmVer($ver) {
    $raw = trim(strval($ver));
    $mcu = '';
    if (preg_match('/_(v\d+(?:\.\d+)+.*)$/i', $raw, $mMcu)) {
      $mcu = strtolower($mMcu[1]);
    }
    $base = preg_replace('/_v\d+(?:\.\d+)+.*$/i', '', $raw);
    $base = preg_replace('/^[vV]/', '', $base);

    if (preg_match('/^(?:(\d+)\.(\d+)\.(\d+)\.)?(?:(\d+)_)?(\d+)_(\d{6})(\d*)$/', $base, $m)) {
      $yymmdd = $m[6];
      if (!self::isValidBuildDateCode($yymmdd)) {
        $yymmdd = '';
      }
      return array(
        'raw' => $raw,
        'base' => strtolower($base),
        'semver' => sprintf('%d.%d.%d', intval($m[1]), intval($m[2]), intval($m[3])),
        'build' => intval($m[5]),
        'date' => $yymmdd,
        'tail' => $m[7],
        'mcu' => $mcu,
      );
    }

    if (preg_match('/(?:^|[._])(\d+)_(\d{6})(\d*)$/', $base, $m2) && self::isValidBuildDateCode($m2[2])) {
      return array(
        'raw' => $raw,
        'base' => strtolower($base),
        'semver' => '0.0.0',
        'build' => intval($m2[1]),
        'date' => $m2[2],
        'tail' => $m2[3],
        'mcu' => $mcu,
      );
    }

    if (preg_match('/^(\d+)\.(\d+)\.(\d+)\.(\d+)/', $base, $m3)) {
      return array(
        'raw' => $raw,
        'base' => strtolower($base),
        'semver' => sprintf('%d.%d.%d', intval($m3[1]), intval($m3[2]), intval($m3[3])),
        'build' => intval($m3[4]),
        'date' => '',
        'tail' => '',
        'mcu' => $mcu,
      );
    }

    return array(
      'raw' => $raw,
      'base' => strtolower($base),
      'semver' => '0.0.0',
      'build' => 0,
      'date' => '',
      'tail' => '',
      'mcu' => $mcu,
    );
  }

  public static function isValidBuildDateCode($yymmdd) {
    if (!is_string($yymmdd) || !preg_match('/^(\d{2})(\d{2})(\d{2})$/', $yymmdd, $m)) {
      return false;
    }
    $yy = intval($m[1]);
    $mm = intval($m[2]);
    $dd = intval($m[3]);
    return ($yy >= 14 && $yy <= 45 && $mm >= 1 && $mm <= 12 && $dd >= 1 && $dd <= 31);
  }

  public static function formatBuildDate($yymmdd) {
    if (!self::isValidBuildDateCode($yymmdd)) {
      return '';
    }
    return sprintf('20%s-%s-%s', substr($yymmdd, 0, 2), substr($yymmdd, 2, 2), substr($yymmdd, 4, 2));
  }

  /**
   * Fetch multiple JSON URLs concurrently via curl_multi.
   */
  protected static function fetchJsonFiles(array $urlsByKey) {
    $mh = curl_multi_init();
    $handles = array();
    foreach ($urlsByKey as $key => $url) {
      $ch = curl_init($url);
      curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_ENCODING => '',
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Jeedom-Reolink-Plugin/1.0 (+https://github.com/Jezza34000/jeedom_reolink_plugin)',
      ));
      curl_multi_add_handle($mh, $ch);
      $handles[$key] = $ch;
    }

    $running = null;
    do {
      $status = curl_multi_exec($mh, $running);
      if ($running > 0 && $status === CURLM_OK) {
        if (curl_multi_select($mh, 1.0) === -1) {
          usleep(50000);
        }
      }
    } while ($running > 0 && $status === CURLM_OK);

    $out = array();
    $errors = array();
    foreach ($handles as $key => $ch) {
      $body = curl_multi_getcontent($ch);
      $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
      $err = curl_error($ch);
      curl_multi_remove_handle($mh, $ch);
      curl_close($ch);

      if ($httpCode < 200 || $httpCode >= 300 || !is_string($body) || $body === '') {
        $errors[] = $key . ' (HTTP ' . $httpCode . ($err !== '' ? ': ' . $err : '') . ')';
        continue;
      }
      $decoded = json_decode($body, true);
      if (!is_array($decoded)) {
        $errors[] = $key . ' (JSON invalide)';
        continue;
      }
      $out[$key] = $decoded;
    }
    curl_multi_close($mh);

    if (!empty($errors)) {
      throw new Exception('Échec récupération index firmware GitHub : ' . implode(', ', $errors));
    }
    return $out;
  }

  /**
   * Fetch and build a compact local index of official Reolink firmware releases.
   */
  public static function fetchIndex($forceRefresh = false) {
    $cacheFile = self::getCacheFilePath();
    $cached = null;
    if (is_file($cacheFile)) {
      $raw = @file_get_contents($cacheFile);
      if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && intval($decoded['schemaVer'] ?? 0) === self::CACHE_SCHEMA_VER && isset($decoded['entries'])) {
          $cached = $decoded;
          if (!$forceRefresh && (time() - intval($decoded['fetchedAt'] ?? 0)) < self::CACHE_TTL) {
            return $cached;
          }
        }
      }
    }

    try {
      $files = self::fetchJsonFiles(array(
        'devices' => self::RAW_BASE_URL . 'devices.json',
        'pak_info' => self::RAW_BASE_URL . 'pak_info.json',
        'live' => self::RAW_BASE_URL . 'firmwares_live.json',
        'archive_support' => self::RAW_BASE_URL . 'firmwares_archives_support.json',
      ));
    } catch (Exception $e) {
      if (is_array($cached)) {
        if (class_exists('log')) {
          log::add('reolink', 'warning', $e->getMessage() . ' — utilisation du cache local');
        }
        $cached['stale'] = true;
        return $cached;
      }
      throw $e;
    }

    $devById = array();
    foreach ($files['devices'] as $dev) {
      if (is_array($dev) && isset($dev['id'])) {
        $devById[intval($dev['id'])] = $dev;
      }
    }

    $metaBySha = array();
    foreach (array_merge($files['live'], $files['archive_support']) as $fw) {
      if (!is_array($fw) || empty($fw['sha256_pak'])) {
        continue;
      }
      $sha = strval($fw['sha256_pak']);
      if (!isset($metaBySha[$sha])) {
        $metaBySha[$sha] = $fw;
      }
    }

    $entries = array();
    foreach ($files['pak_info'] as $sha => $pi) {
      if (!is_array($pi)) {
        continue;
      }
      if (!empty($pi['beta']) || !empty($pi['user_hosted_only'])) {
        continue;
      }
      $officialUrls = array();
      foreach (($pi['download'] ?? array()) as $dl) {
        if (self::isOfficialReolinkUrl($dl)) {
          $officialUrls[] = trim($dl);
        }
      }
      if (empty($officialUrls)) {
        continue;
      }
      sort($officialUrls);

      $info = is_array($pi['info'] ?? null) ? $pi['info'] : array();
      $modelId = intval($pi['model_id'] ?? 0);
      $hwVerId = intval($pi['hw_ver_id'] ?? 0);
      $dev = $devById[$modelId] ?? array();

      $hwSet = array();
      foreach (array($info['board_type'] ?? '', $info['board_name'] ?? '', $info['detail_machine_type'] ?? '') as $b) {
        $clean = self::cleanHw($b);
        if ($clean !== '') {
          $hwSet[$clean] = true;
        }
      }
      foreach (($dev['hardwareVersions'] ?? array()) as $hwItem) {
        if (intval($hwItem['id'] ?? -1) === $hwVerId && !empty($hwItem['title'])) {
          $parts = preg_split('/\s+or\s+|\s+/i', strval($hwItem['title']));
          foreach ($parts as $part) {
            $clean = self::cleanHw($part);
            if ($clean !== '') {
              $hwSet[$clean] = true;
            }
          }
        }
      }
      if (empty($hwSet)) {
        continue;
      }

      $pakVer = trim(strval($info['firmware_version_prefix'] ?? '') . '.' . strval($info['version_file'] ?? ''), '.');
      $meta = $metaBySha[strval($sha)] ?? array();
      $dlUrl = $officialUrls[0];
      $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', trim(strval($meta['filename'] ?? '')));
      if ($filename !== '' && strpos($dlUrl, 'wp-content') !== false && strpos($dlUrl, 'download_name=') === false) {
        $dlUrl .= (strpos($dlUrl, '?') === false ? '?' : '&') . 'download_name=' . rawurlencode($filename . '.zip');
      }

      $changelog = array();
      if (!empty($meta['changelog']) && is_array($meta['changelog'])) {
        foreach ($meta['changelog'] as $line) {
          $line = trim(strval($line));
          if ($line !== '') {
            $changelog[] = $line;
          }
        }
      }

      $entries[] = array(
        'sha' => strval($sha),
        'modelId' => $modelId,
        'devTitle' => strval($dev['title'] ?? ''),
        'dispType' => strval($info['display_type_info'] ?? ''),
        'hws' => array_keys($hwSet),
        'pakVer' => $pakVer,
        'fullVer' => trim(strval($meta['version'] ?? '')) !== '' ? trim(strval($meta['version'])) : $pakVer,
        'buildDate' => strval($info['build_date'] ?? ''),
        'url' => $dlUrl,
        'changelog' => $changelog,
      );
    }

    $index = array(
      'schemaVer' => self::CACHE_SCHEMA_VER,
      'fetchedAt' => time(),
      'entryCount' => count($entries),
      'entries' => $entries,
    );

    $dir = dirname($cacheFile);
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }
    $tmpFile = $cacheFile . '.tmp.' . getmypid();
    if (@file_put_contents($tmpFile, json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
      @rename($tmpFile, $cacheFile);
    } else {
      @unlink($tmpFile);
    }

    return $index;
  }

  /**
   * Compare installed camera/NVR firmware against the official Reolink archive index.
   */
  public static function checkDevice($model, $hardVer, $firmVer, $index = null) {
    $modelStr = trim(strval($model));
    $hwClean = self::cleanHw($hardVer);
    $firmStr = trim(strval($firmVer));

    if ($modelStr === '' || $hwClean === '' || $firmStr === '') {
      return array(
        'status' => 'missing_info',
        'updateAvailable' => false,
        'installedVer' => $firmStr,
        'latestVer' => '',
        'latestPakVer' => '',
        'buildDate' => '',
        'downloadUrl' => '',
        'sha256Pak' => '',
        'changelog' => array(),
      );
    }

    if (!is_array($index) || !isset($index['entries'])) {
      $index = self::fetchIndex(false);
    }

    $hwCandidates = array();
    foreach ($index['entries'] as $entry) {
      if (in_array($hwClean, $entry['hws'] ?? array(), true)) {
        $hwCandidates[] = $entry;
      }
    }

    if (empty($hwCandidates)) {
      return array(
        'status' => 'not_found',
        'updateAvailable' => false,
        'installedVer' => $firmStr,
        'latestVer' => '',
        'latestPakVer' => '',
        'buildDate' => '',
        'downloadUrl' => '',
        'sha256Pak' => '',
        'changelog' => array(),
      );
    }

    $mExact = self::cleanModel($modelStr, false);
    $matched = array();
    foreach ($hwCandidates as $cand) {
      if ($mExact === self::cleanModel($cand['dispType'] ?? '', false)
        || $mExact === self::cleanModel($cand['devTitle'] ?? '', false)) {
        $matched[] = $cand;
      }
    }

    // Fallback: strip leading brand prefix ("Reolink " / "E Series ") only if unambiguous (single modelId)
    if (empty($matched)) {
      $mShort = self::cleanModel($modelStr, true);
      $shortMatches = array();
      $modelIds = array();
      foreach ($hwCandidates as $cand) {
        if ($mShort !== '' && (
          $mShort === self::cleanModel($cand['dispType'] ?? '', true)
          || $mShort === self::cleanModel($cand['devTitle'] ?? '', true)
        )) {
          $shortMatches[] = $cand;
          $modelIds[intval($cand['modelId'] ?? 0)] = true;
        }
      }
      if (count($modelIds) === 1) {
        $matched = $shortMatches;
      }
    }

    if (empty($matched)) {
      return array(
        'status' => 'not_found',
        'updateAvailable' => false,
        'installedVer' => $firmStr,
        'latestVer' => '',
        'latestPakVer' => '',
        'buildDate' => '',
        'downloadUrl' => '',
        'sha256Pak' => '',
        'changelog' => array(),
      );
    }

    usort($matched, function ($a, $b) {
      $pa = self::parseFirmVer($a['pakVer'] ?? '');
      $pb = self::parseFirmVer($b['pakVer'] ?? '');
      $da = $pa['date'] !== '' ? $pa['date'] : strval($a['buildDate'] ?? '');
      $db = $pb['date'] !== '' ? $pb['date'] : strval($b['buildDate'] ?? '');
      if ($da !== $db) {
        return strcmp($db, $da);
      }
      $semCmp = version_compare($pb['semver'], $pa['semver']);
      if ($semCmp !== 0) {
        return $semCmp;
      }
      return $pb['build'] <=> $pa['build'];
    });

    $best = $matched[0];
    $instP = self::parseFirmVer($firmStr);
    $bestP = self::parseFirmVer($best['pakVer'] ?? '');
    $bestDate = $bestP['date'] !== '' ? $bestP['date'] : strval($best['buildDate'] ?? '');

    $isNewer = false;
    if ($instP['base'] !== '' && $bestP['base'] !== '' && $instP['base'] !== $bestP['base']) {
      if ($instP['date'] !== '' && $bestDate !== '') {
        if (strcmp($bestDate, $instP['date']) > 0) {
          $isNewer = true;
        } elseif ($bestDate === $instP['date']) {
          $semCmp = version_compare($bestP['semver'], $instP['semver']);
          if ($semCmp > 0 || ($semCmp === 0 && $bestP['build'] > $instP['build'])) {
            $isNewer = true;
          }
        }
      } else {
        $semCmp = version_compare($bestP['semver'], $instP['semver']);
        if ($semCmp > 0 || ($semCmp === 0 && $bestP['build'] > $instP['build'])) {
          $isNewer = true;
        }
      }
    }

    // Re-verify URL against official whitelist defensively even when reading from local cache
    $safeUrl = self::isOfficialReolinkUrl($best['url'] ?? '') ? $best['url'] : '';
    if ($safeUrl === '') {
      return array(
        'status' => 'not_found',
        'updateAvailable' => false,
        'installedVer' => $firmStr,
        'latestVer' => '',
        'latestPakVer' => '',
        'buildDate' => '',
        'downloadUrl' => '',
        'sha256Pak' => '',
        'changelog' => array(),
      );
    }

    return array(
      'status' => $isNewer ? 'update_available' : 'up_to_date',
      'updateAvailable' => $isNewer,
      'installedVer' => $firmStr,
      'latestVer' => strval($best['fullVer'] ?? $best['pakVer']),
      'latestPakVer' => strval($best['pakVer'] ?? ''),
      'buildDate' => self::formatBuildDate($bestDate),
      'buildDateRaw' => $bestDate,
      'downloadUrl' => $safeUrl,
      'sha256Pak' => strval($best['sha'] ?? ''),
      'changelog' => is_array($best['changelog'] ?? null) ? $best['changelog'] : array(),
    );
  }
}
