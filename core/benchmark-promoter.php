<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Promotes repeated verified lessons into persistent regression candidates. */
class Design_Core_Elementor_Benchmark_Promoter {
    const VERSION = 1;
    const MIN_CONFIDENCE = 0.90;
    const MIN_VERIFIED_HITS = 2;

    private $store;

    public function __construct( Design_Core_Elementor_Design_Memory_Store $store = null ) {
        $this->store = $store ?: new Design_Core_Elementor_Design_Memory_Store();
    }

    public function observe_lesson( array $lesson ) {
        if ( empty( $lesson['verified'] ) ) { return null; }
        if ( (float) ( $lesson['confidence'] ?? 0 ) < self::MIN_CONFIDENCE ) { return null; }
        if ( (int) ( $lesson['verified_hits'] ?? 0 ) < self::MIN_VERIFIED_HITS ) { return null; }
        return $this->store->queue_benchmark_candidate( array(
            'signature' => (string) ( $lesson['signature'] ?? '' ),
            'strategy' => (string) ( $lesson['strategy'] ?? '' ),
            'source_kind' => (string) ( $lesson['source_kind'] ?? '' ),
            'source_fingerprint' => (string) ( $lesson['source_fingerprint'] ?? '' ),
            'confidence' => (float) ( $lesson['confidence'] ?? 0 ),
            'verified_hits' => (int) ( $lesson['verified_hits'] ?? 1 ),
        ) );
    }

    public function catalog() {
        return array(
            'version' => self::VERSION,
            'promotion_policy' => array( 'min_confidence' => self::MIN_CONFIDENCE, 'min_verified_hits' => self::MIN_VERIFIED_HITS ),
            'candidates' => $this->store->benchmark_candidates(),
        );
    }
}
