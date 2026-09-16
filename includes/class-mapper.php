<?php
/**
 * Maps a DD Pro listing onto the site's existing WooCommerce + ACF schema.
 *
 * Every ACF field needs two meta rows: the value under `field_name` and the
 * field key under `_field_name`. Without the second row ACF cannot render the
 * value in the product editor. The keys below were read from the live database
 * — do not invent them.
 *
 * @package ADCT_DDPro
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ADCT_DDPro_Mapper {

	/**
	 * ACF field keys as they exist on autodealsuae.com.
	 *
	 * @var array<string,string>
	 */
	public const ACF_KEYS = array(
		'make'                => 'field_68820fad66506',
		'year'                => 'field_6881f4c3e6168',
		'trim'                => 'field_69dcb31d37d74',
		'body_type'           => 'field_68820ff16650b',
		'condition'           => 'field_68820fd866508',
		'mileage'             => 'field_6881f444e6165',
		'transmission'        => 'field_6881f4ade6166',
		'fuel_type'           => 'field_6881f4b9e6167',
		'cylinders'           => 'field_68820fe366509',
		'color'               => 'field_68820fff6650d',
		'performance'         => 'field_6881f4cee6169',
		'assembly'            => 'field_68820ff86650c',
		'warranty'            => 'field_68820fe96650a',
		'insurance'           => 'field_688210076650e',
		'location'            => 'field_6882100e6650f',
		'car_location'        => 'field_688238c650f2c',
		'date'                => 'field_68820fcc66507',
		'official_video_url'  => 'field_6882546be3c24',
		'official_video_image' => 'field_6882545ae3c23',
		'showroom_landline'   => 'field_6882383331e84',
		'link_url'            => 'field_688238fb50f2e',
		'link_text'           => 'field_688238dc50f2d',
		'sales_agents'        => 'field_69fc9755407d5',
	);

	/**
	 * DD Pro transmission_type.name -> the site's existing wording.
	 *
	 * The live catalogue is dominated by the bare words "Automatic" (422 cars)
	 * and "Manual" (5), so we normalise to those rather than adding new variants.
	 *
	 * @var array<string,string>
	 */
	private const TRANSMISSION = array(
		'classic automatic (at)'      => 'Automatic',
		'automatic'                   => 'Automatic',
		'automated manual (amt)'      => 'Automatic',
		'dual-clutch (dct)'           => 'Automatic',
		'continuously variable (cvt)' => 'Automatic',
		'robotised manual'            => 'Automatic',
		'manual'                      => 'Manual',
		'manual (mt)'                 => 'Manual',
	);

	/**
	 * DD Pro body_type.name -> existing wording.
	 *
	 * @var array<string,string>
	 */
	private const BODY_TYPE = array(
		'suv'         => 'SUV',
		'crossover'   => 'SUV',
		'sedan'       => 'Sedan',
		'saloon'      => 'Sedan',
		'coupe'       => 'Coupe',
		'coupé'       => 'Coupe',
		'convertible' => 'Convertible',
		'cabriolet'   => 'Convertible',
		'roadster'    => 'Convertible',
		'hatchback'   => 'Hatchback',
		'pickup'      => 'Pick Up Truck',
		'pick-up'     => 'Pick Up Truck',
		'van'         => 'Van',
		'minivan'     => 'Van',
		'wagon'       => 'Wagon',
		'estate'      => 'Wagon',
	);

	/**
	 * DD Pro regional_specs.name -> existing wording. The feed sends
	 * "GCC specs" with a lowercase s; the site uses "GCC Specs" on 259 cars.
	 *
	 * @var array<string,string>
	 */
	private const ASSEMBLY = array(
		'gcc specs'      => 'GCC Specs',
		'gcc'            => 'GCC Specs',
		'european specs' => 'European Specs',
		'euro specs'     => 'European Specs',
		'japanese specs' => 'Japanese Specs',
		'american specs' => 'American Specs',
		'us specs'       => 'American Specs',
		'korean specs'   => 'Korean Specs',
		'chinese specs'  => 'Chinese Specs',
		'german specs'   => 'German Specs',
	);

	/**
	 * DD Pro engine_type.name -> existing wording. These already line up.
	 *
	 * @var array<string,string>
	 */
	private const FUEL = array(
		'petrol'         => 'Petrol',
		'gasoline'       => 'Petrol',
		'diesel'         => 'Diesel',
		'hybrid'         => 'Hybrid',
		'mild hybrid'    => 'Hybrid',
		'plug-in hybrid' => 'Plug-in-Hybrid',
		'electric'       => 'Electric',
	);

	/** Locations block from the feed, keyed by id. @var array<string,array<string,mixed>> */
	private array $locations = array();

	/**
	 * @param array<int,array<string,mixed>> $locations Feed locations array.
	 */
	public function __construct( array $locations = array() ) {
		foreach ( $locations as $loc ) {
			if ( isset( $loc['id'] ) ) {
				$this->locations[ (string) $loc['id'] ] = $loc;
			}
		}
	}

	/**
	 * Safely read a nested value.
	 *
	 * @param array<string,mixed> $a    Source array.
	 * @param string              $path Dot path, e.g. "brand.name".
	 * @return mixed|null
	 */
	private function pick( array $a, string $path ) {
		$cur = $a;
		foreach ( explode( '.', $path ) as $seg ) {
			if ( ! is_array( $cur ) || ! array_key_exists( $seg, $cur ) ) {
				return null;
			}
			$cur = $cur[ $seg ];
		}
		return $cur;
	}

	/**
	 * Normalise via a lookup table, falling back to the original value so an
	 * unmapped option is visible in the data rather than silently blanked.
	 *
	 * @param array<string,string> $map   Lookup.
	 * @param mixed                $value Raw value.
	 */
	private function norm( array $map, $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}
		$key = strtolower( trim( $value ) );
		return $map[ $key ] ?? trim( $value );
	}

	/**
	 * Build the model name for titles: "Patrol Nismo" style.
	 *
	 * @param array<string,mixed> $l Listing.
	 */
	public function model_name( array $l ): string {
		$model   = (string) ( $this->pick( $l, 'model.name' ) ?? '' );
		$version = (string) ( $this->pick( $l, 'model_version.name' ) ?? '' );

		// model_version.name often already contains the model ("Patrol Nismo").
		if ( '' !== $version && '' !== $model && str_starts_with( strtolower( $version ), strtolower( $model ) ) ) {
			return $version;
		}
		return trim( $model . ' ' . $version );
	}

	/**
	 * Smallest / largest engine displacement in cc we will print.
	 *
	 * DD Pro data is not always sane. A real listing in the live feed (a 2025
	 * BMW M5, dd_id 161984-CHCZT) reports engine_displacement.value = 654 with
	 * no cylinder count — the actual car is a 4.4L V8 plug-in hybrid. Rendered
	 * blindly that produced the title "... | 0.7L", which is worse than printing
	 * nothing at all. Anything outside this range is treated as bad source data.
	 */
	private const CC_MIN = 800;
	private const CC_MAX = 9000;

	/**
	 * Engine descriptor for titles, e.g. "5.6L V8".
	 *
	 * Returns an empty string rather than an implausible one.
	 *
	 * @param array<string,mixed> $l Listing.
	 */
	private function engine_label( array $l ): string {
		$cc  = (int) ( $this->pick( $l, 'engine_displacement.value' ) ?? 0 );
		$cyl = (int) ( $l['engine_cylinder_count'] ?? 0 );

		$parts = array();
		if ( $cc >= self::CC_MIN && $cc <= self::CC_MAX ) {
			$parts[] = number_format( $cc / 1000, 1 ) . 'L';
		}
		if ( $cyl >= 2 && $cyl <= 16 ) {
			$parts[] = ( in_array( $cyl, array( 8, 10, 12, 16 ), true ) ? 'V' : 'i' ) . $cyl;
		}
		return implode( ' ', $parts );
	}

	/**
	 * Data-quality problems in a listing, so they surface in the sync log
	 * instead of silently reaching a product page.
	 *
	 * @param array<string,mixed> $l Listing.
	 * @return array<int,string>
	 */
	public function data_warnings( array $l ): array {
		$w  = array();
		$cc = (int) ( $this->pick( $l, 'engine_displacement.value' ) ?? 0 );

		if ( $cc > 0 && ( $cc < self::CC_MIN || $cc > self::CC_MAX ) ) {
			$w[] = sprintf( 'implausible engine displacement %dcc — fix in DD Pro', $cc );
		}
		if ( 0 === $cc ) {
			$w[] = 'no engine displacement';
		}
		if ( ! isset( $l['engine_cylinder_count'] ) ) {
			$w[] = 'no cylinder count';
		}
		if ( null === $this->pick( $l, 'body_color.name' ) ) {
			$w[] = 'no colour';
		}
		if ( ! isset( $l['equipments'] ) || ! is_array( $l['equipments'] ) || ! $l['equipments'] ) {
			$w[] = 'no equipment list';
		}
		if ( '' === trim( (string) ( $this->pick( $l, 'vin' ) ?? '' ) ) ) {
			$w[] = 'no VIN';
		}
		if ( $this->status_is_unknown( $l ) ) {
			$w[] = sprintf(
				'UNRECOGNISED status code "%s" — treated as still for sale; add it to UNAVAILABLE_CODES if it means sold',
				(string) ( $this->pick( $l, 'status.code' ) ?? '(empty)' )
			);
		}

		return $w;
	}

	/**
	 * Suggested product title, following the live pattern:
	 * "2021 Audi RS6 Avant 4.0 TFSI Quattro Tiptronic | GCC Specs | Under Warranty | 4.0"
	 *
	 * This is only ever used when CREATING a product, and the product is created
	 * as a draft precisely so a human can rewrite this before it goes live.
	 *
	 * @param array<string,mixed> $l Listing.
	 */
	public function suggested_title( array $l ): string {
		// DD Pro now writes a ready-made headline as the FIRST line of the
		// description field, e.g. "2022 Porsche Cayenne GTS | GCC Specs | Under
		// Warranty | Low Mileage | Excellent Condition | 4.0L V8". That is exactly
		// the wording the showroom wants, so prefer it and only fall back to a
		// generated title when the feed has no usable headline.
		$from_feed = $this->feed_headline( $l );
		if ( '' !== $from_feed ) {
			// If DD Pro's headline is already a reasonable length, use it verbatim.
			// Only reduce it to the template when it exceeds the configured cap.
			$max = (int) ADCT_DDPro_Settings::get( 'title_max_length' );
			if ( $max > 0 && mb_strlen( $from_feed ) <= $max ) {
				return $from_feed;
			}
			$short = $this->trim_headline_for_title( $from_feed, $this->mileage_tier( $l ) );
			if ( '' !== $short ) {
				return $short;
			}
		}

		$year  = (int) ( $l['year'] ?? 0 );
		$brand = (string) ( $this->pick( $l, 'brand.name' ) ?? '' );

		$head = trim( ( $year > 0 ? $year . ' ' : '' ) . $brand . ' ' . $this->model_name( $l ) );

		$bits = array( $head );

		$assembly = $this->norm( self::ASSEMBLY, $this->pick( $l, 'regional_specs.name' ) );
		if ( '' !== $assembly ) {
			$bits[] = $assembly;
		}
		if ( true === $this->pick( $l, 'warranty.value' ) ) {
			$bits[] = 'Under Warranty';
		}
		if ( true === $this->pick( $l, 'service_contract.value' ) ) {
			$bits[] = 'Service Contract';
		}
		if ( 'No accidents' === $this->pick( $l, 'accident.name' ) ) {
			$bits[] = 'No Accidents';
		}

		$engine = $this->engine_label( $l );
		if ( '' !== $engine ) {
			$bits[] = $engine;
		}

		return implode( ' | ', array_filter( $bits ) );
	}

	/** How many middle descriptors (between identity and engine) a title keeps. */
	private const TITLE_DESCRIPTORS = 3;

	/**
	 * Fallback descriptors for a non-GCC car whose headline mentions none of its
	 * own. "Full Option" is deliberately NOT here — it is only ever used when DD
	 * Pro actually says it (i.e. picked from the headline, never invented).
	 *
	 * @var array<int,string>
	 */
	private const DEFAULT_DESCRIPTORS = array( 'Best in Class', 'Pristine Condition', 'Well Maintained' );

	/**
	 * The accurate low-mileage wording for a car, derived from its real km so the
	 * claim always matches the number (rather than trusting the feed's wording):
	 *   under 100 km  -> "Very Low Mileage"
	 *   100–999 km    -> "Low Mileage"
	 *   1,000+ km     -> "" (no low-mileage claim)
	 *
	 * @param array<string,mixed> $l Listing.
	 */
	private function mileage_tier( array $l ): string {
		if ( ! isset( $l['mileage_km'] ) || ! is_numeric( $l['mileage_km'] ) ) {
			return '';
		}
		$km = (int) $l['mileage_km'];
		if ( $km < 0 ) {
			return '';
		}
		if ( $km < 100 ) {
			return 'Very Low Mileage';
		}
		if ( $km < 1000 ) {
			return 'Low Mileage';
		}
		return '';
	}

	/**
	 * Shorten a full DD Pro headline down to a product title.
	 *
	 * The feed headline can run very long, e.g. "2023 Porsche 718 Cayman GT4 RS
	 * Weissach | GCC Specs | Under Warranty | Full Service History | Extremely Low
	 * Mileage | Race-Inspired Performance | … | 4.0L F6". The title keeps:
	 *
	 *   identity (year make model trim) | up to 3 descriptors | engine
	 *
	 * The 3 descriptor slots prefer Specs / Warranty / Service (the trust signals).
	 * A car that has fewer than 3 of those — e.g. an import-spec car with no
	 * warranty or service history — backfills the empty slots with its other
	 * headline details (Low Mileage, Fully Loaded, …) so the title never collapses
	 * to just "identity | engine". Chosen descriptors keep their original order.
	 *
	 * @param string $headline     Full pipe-delimited headline.
	 * @param string $mileage_word Accurate low-mileage wording from mileage_tier(),
	 *                             used only in the non-GCC backfill; '' to omit.
	 * @return string Shortened title, or '' if the headline had no segments.
	 */
	private function trim_headline_for_title( string $headline, string $mileage_word = '' ): string {
		$parts = array_values(
			array_filter(
				array_map( 'trim', explode( '|', $headline ) ),
				static fn( string $p ): bool => '' !== $p
			)
		);
		if ( ! $parts ) {
			return '';
		}

		$identity = $parts[0];
		$rest     = array_slice( $parts, 1 );

		// Pull the engine segment (e.g. "4.0L F6") out — it always goes last.
		$engine = '';
		foreach ( $rest as $i => $seg ) {
			if ( preg_match( '/\b\d+(?:\.\d+)?\s*L\b/i', $seg ) ) {
				$engine = $seg;
				unset( $rest[ $i ] );
			}
		}
		$rest = array_values( $rest );

		// Priority slots: Specs / Warranty / Service, in headline order.
		$is_key = static function ( string $s ): bool {
			// "Specs" (plural) is the regional tag — NOT the "Spec" inside "High-Spec".
			return (bool) preg_match( '/\bspecs\b/i', $s )
				|| (bool) preg_match( '/\bwarranty\b/i', $s )
				|| (bool) preg_match( '/\bservice\b/i', $s );
		};

		$chosen = array();
		foreach ( $rest as $i => $seg ) {
			if ( $is_key( $seg ) ) {
				$chosen[ $i ] = $seg;
			}
		}
		if ( ! empty( $chosen ) ) {
			// Car has trust signals — keep just those (drop all fluff).
			ksort( $chosen ); // restore headline order
			$chosen = array_slice( array_values( $chosen ), 0, self::TITLE_DESCRIPTORS );
		} else {
			/*
			 * No trust signals (typically an import-spec car). Backfill:
			 *  1. the accurate low-mileage wording (from the real km), first;
			 *  2. DD Pro's own remaining descriptors — but NOT its mileage claim,
			 *     which we replace with the accurate one above;
			 *  3. only if DD Pro mentioned no usable descriptor at all, our
			 *     default condition words ("Full Option" is never invented here).
			 */
			$fill = array();
			if ( '' !== $mileage_word ) {
				$fill[] = $mileage_word;
			}

			$ddpro = array();
			foreach ( $rest as $seg ) {
				if ( preg_match( '/mil(?:e|l)?age/i', $seg ) ) {
					continue; // DD Pro's mileage claim — superseded by the accurate tier.
				}
				$ddpro[] = $seg;
			}

			$source = ! empty( $ddpro ) ? $ddpro : self::DEFAULT_DESCRIPTORS;
			foreach ( $source as $seg ) {
				if ( count( $fill ) >= self::TITLE_DESCRIPTORS ) {
					break;
				}
				$fill[] = $seg;
			}

			$chosen = array_slice( $fill, 0, self::TITLE_DESCRIPTORS );
		}

		$bits = array_merge( array( $identity ), $chosen );
		if ( '' !== $engine ) {
			$bits[] = $engine;
		}

		return implode( ' | ', $bits );
	}

	/**
	 * Known wording fixes applied to the feed headline before it becomes a title
	 * or short description. Each entry is [ pattern, replacement ] used with
	 * preg_replace, so word boundaries keep the fixes from over-matching. Extend
	 * this list whenever DD Pro ships a new typo or spelling quirk.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private const WORDING_FIXES = array(
		array( '/\bMecredes\b/i', 'Mercedes' ),          // recurring DD Pro typo
		array( '/\bMercedes(?!-)\s+Benz\b/i', 'Mercedes-Benz' ), // standardise the hyphen
		array( '/\bRolls\s+Royce\b/i', 'Rolls-Royce' ),  // add the hyphen
		array( '/\bGCC\b(?!\s*Specs)/i', 'GCC Specs' ),  // "GCC" / "GCC Spec" -> "GCC Specs"
		array( '/\bGCC\s+Spec\b(?!s)/i', 'GCC Specs' ),
	);

	/**
	 * Apply the known wording fixes (typos, brand spelling) to a piece of feed
	 * text, then tidy whitespace around the pipes.
	 *
	 * @param string $s Text from the feed.
	 */
	private function clean_wording( string $s ): string {
		foreach ( self::WORDING_FIXES as $fix ) {
			$s = (string) preg_replace( $fix[0], $fix[1], $s );
		}
		// Normalise spacing around the " | " separators and collapse doubles.
		$s = (string) preg_replace( '/\s*\|\s*/', ' | ', $s );
		$s = (string) preg_replace( '/\s{2,}/', ' ', $s );
		return trim( $s );
	}

	/**
	 * The showroom headline out of the DD Pro description.
	 *
	 * DD Pro uses two different description layouts: a clean one where the headline
	 * sits in the first <p>, and a long one where the headline is plain text
	 * between <br> tags, followed by agent phone numbers, the showroom address, an
	 * "About Us" blurb, a "BUY | SELL | TRADE-IN" line and the internal DD ID.
	 *
	 * Rather than trust position, we pick the ONE line that unmistakably is the
	 * headline: it starts with a 4-digit year and contains the " | " the showroom
	 * format uses (e.g. "2023 Porsche 718 Cayman GT4 RS Weissach | GCC Specs | …").
	 * That rule matches both layouts and skips every noisy line — "Show Number",
	 * "Car Info:", "About Us", "BUY | SELL …" (no leading year) and "DD ID: …".
	 *
	 * Returns '' when no such line exists, so the caller falls back to a generated
	 * title.
	 *
	 * @param array<string,mixed> $l Listing.
	 */
	private function feed_headline( array $l ): string {
		$raw = '';
		if ( isset( $l['description'] ) && is_array( $l['description'] ) ) {
			foreach ( $l['description'] as $d ) {
				if ( is_array( $d ) && 'en' === ( $d['lang'] ?? '' ) ) {
					$raw = (string) ( $d['value'] ?? '' );
					break;
				}
			}
		}
		if ( '' === $raw ) {
			return '';
		}

		// Flatten both layouts (<br> and <p>) into plain lines.
		$text = preg_replace( '#<\s*br\s*/?\s*>#i', "\n", $raw );
		$text = preg_replace( '#</?\s*p[^>]*>#i', "\n", (string) $text );
		$text = html_entity_decode( (string) wp_strip_all_tags( (string) $text ), ENT_QUOTES );

		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( preg_replace( '/[ \t]+/', ' ', (string) $line ) ?? '' );
			if ( '' === $line ) {
				continue;
			}
			// The headline: starts with a 19xx/20xx year and carries a pipe.
			if ( preg_match( '/^(19|20)\d{2}\s+\S.*\|/', $line ) ) {
				return $this->clean_wording( $line );
			}
		}

		return '';
	}

	/**
	 * Placeholder body copy for a newly created draft.
	 *
	 * Deliberately marked as needing a human. The feed's own description field
	 * currently contains only "<p>DD ID: 161392-CHCZT</p>", which is not
	 * publishable content.
	 *
	 * @param array<string,mixed> $l Listing.
	 */
	public function draft_content( array $l ): string {
		$feed_desc = '';
		if ( isset( $l['description'] ) && is_array( $l['description'] ) ) {
			foreach ( $l['description'] as $d ) {
				if ( is_array( $d ) && 'en' === ( $d['lang'] ?? '' ) ) {
					$feed_desc = (string) ( $d['value'] ?? '' );
					break;
				}
			}
		}

		// Treat the "DD ID: xxx" placeholder as empty.
		if ( preg_match( '/^\s*<p>\s*DD ID:[^<]*<\/p>\s*$/i', $feed_desc ) ) {
			$feed_desc = '';
		}

		if ( '' !== trim( wp_strip_all_tags( $feed_desc ) ) ) {
			return wp_kses_post( $feed_desc );
		}

		$specs = array();
		foreach ( array(
			'Year'         => $l['year'] ?? '',
			'Mileage'      => isset( $l['mileage_km'] ) ? number_format( (float) $l['mileage_km'] ) . ' km' : '',
			'Engine'       => $this->pick( $l, 'engine_name' ) ?? '',
			'Transmission' => $this->pick( $l, 'transmission_name' ) ?? '',
			'Specs'        => $this->norm( self::ASSEMBLY, $this->pick( $l, 'regional_specs.name' ) ),
			'Condition'    => $this->pick( $l, 'general_condition.name' ) ?? '',
		) as $label => $value ) {
			if ( '' !== (string) $value ) {
				$specs[] = '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( (string) $value ) . '</li>';
			}
		}

		return "<!-- DD Pro import: replace this with proper sales copy before publishing. -->\n"
			. '<p>' . esc_html( $this->suggested_title( $l ) ) . "</p>\n"
			. ( $specs ? "<ul>\n" . implode( "\n", $specs ) . "\n</ul>\n" : '' );
	}

	/**
	 * Short product description (the WooCommerce excerpt).
	 *
	 * Unlike the title, the short description keeps the FULL DD Pro headline (all
	 * of its detail), followed by a blank line and the model year and mileage:
	 *
	 *   2023 Porsche 718 Cayman GT4 RS Weissach | GCC Specs | … | 4.0L F6
	 *
	 *   2023 Model
	 *   5,747 Kms
	 *
	 * The year and mileage are taken from the feed's own fields (not scraped from
	 * the description text), so they are always present and current. When the feed
	 * has no headline, the generated title is used as the first line instead.
	 *
	 * @param array<string,mixed> $l Listing.
	 */
	public function short_description( array $l ): string {
		$head = $this->feed_headline( $l );
		if ( '' === $head ) {
			$head = $this->suggested_title( $l );
		}

		$lines = array( $head, '' );

		$year = (int) ( $l['year'] ?? 0 );
		if ( $year > 0 ) {
			$lines[] = $year . ' Model';
		}
		if ( isset( $l['mileage_km'] ) && is_numeric( $l['mileage_km'] ) ) {
			$lines[] = number_format( (float) $l['mileage_km'] ) . ' Kms';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Map a listing to the ACF spec fields.
	 *
	 * @param array<string,mixed> $l Listing.
	 * @return array<string,string> field_name => value
	 */
	public function acf_fields( array $l ): array {
		$settings = ADCT_DDPro_Settings::all();

		$cond_code = (string) ( $this->pick( $l, 'condition_type.code' ) ?? '' );
		$condition = 'new' === $cond_code
			? (string) $settings['condition_new']
			: (string) $settings['condition_used'];

		$mileage = isset( $l['mileage_km'] ) ? (float) $l['mileage_km'] : null;
		$power   = $this->pick( $l, 'engine_power.value' );

		$loc_id   = (string) ( $l['dealer_location_id'] ?? '' );
		$location = $this->locations[ $loc_id ] ?? array();

		$city = (string) ( $location['city_name'] ?? '' );

		$fields = array(
			'make'         => (string) ( $this->pick( $l, 'brand.name' ) ?? '' ),
			'year'         => isset( $l['year'] ) ? (string) (int) $l['year'] : '',
			'trim'         => (string) (
				$this->pick( $l, 'trim.name' )
				?? $this->pick( $l, 'model_version_short_name' )
				?? ''
			),
			'body_type'    => $this->norm( self::BODY_TYPE, $this->pick( $l, 'body_type.name' ) ),
			'condition'    => $condition,
			'mileage'      => null === $mileage ? '' : number_format( $mileage ),
			'transmission' => $this->norm( self::TRANSMISSION, $this->pick( $l, 'transmission_type.name' ) ),
			'fuel_type'    => $this->norm( self::FUEL, $this->pick( $l, 'engine_type.name' ) ),
			'cylinders'    => isset( $l['engine_cylinder_count'] ) ? (string) (int) $l['engine_cylinder_count'] : '',
			'color'        => (string) ( $this->pick( $l, 'body_color.name' ) ?? '' ),
			'performance'  => null === $power ? '' : (string) (int) $power,
			'assembly'     => $this->norm( self::ASSEMBLY, $this->pick( $l, 'regional_specs.name' ) ),
			'warranty'     => true === $this->pick( $l, 'warranty.value' ) ? 'Yes' : 'No',
			'location'     => '' !== $city ? $city . ', United Arab Emirates' : (string) $settings['default_location'],
			'car_location' => (string) ( $location['address'] ?? '' ),
		);

		/**
		 * Filter the mapped ACF values before they are written.
		 *
		 * @param array<string,string> $fields Mapped values.
		 * @param array<string,mixed>  $l      Raw DD Pro listing.
		 */
		return apply_filters( 'adct_ddpro_acf_fields', $fields, $l );
	}

	/**
	 * Photo URLs for a listing.
	 *
	 * @param array<string,mixed> $l Listing.
	 * @return array<int,string>
	 */
	public function photo_urls( array $l ): array {
		$out = array();
		if ( isset( $l['photos'] ) && is_array( $l['photos'] ) ) {
			foreach ( $l['photos'] as $p ) {
				$path = is_array( $p ) ? ( $p['path'] ?? '' ) : $p;
				if ( is_string( $path ) && '' !== $path && wp_http_validate_url( $path ) ) {
					$out[] = $path;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Price in AED, or null when absent.
	 *
	 * @param array<string,mixed> $l Listing.
	 */
	public function price( array $l ): ?float {
		if ( ! isset( $l['price'] ) || ! is_numeric( $l['price'] ) ) {
			return null;
		}
		$p = (float) $l['price'];
		return $p > 0 ? $p : null;
	}

	/**
	 * Status codes that mean "no longer purchasable on the website".
	 *
	 * The DD Pro listing screen offers three actions — "Withdraw from sale",
	 * "Reserve for sale" and "Close" — plus the "For sale" state (`inSale`).
	 * The exact codes those actions emit have not been observed yet, so several
	 * plausible spellings of each are listed.
	 *
	 * @var array<int,string>
	 */
	private const UNAVAILABLE_CODES = array(
		'sold', 'closed', 'close', 'archived', 'archive',
		'reserved', 'reservedforsale', 'reserve',
		'withdrawn', 'withdrawnfromsale', 'withdraw',
		'notinsale', 'notonsale', 'deleted', 'removed', 'inactive', 'disabled',
	);

	/** Status codes known to mean "available". @var array<int,string> */
	private const AVAILABLE_CODES = array( 'insale', 'onsale', 'active', 'published', 'available' );

	/**
	 * Whether the feed still considers this car available.
	 *
	 * An unrecognised code is treated as AVAILABLE on purpose: guessing wrong in
	 * the other direction would pull a ranking page's stock down for no reason.
	 * Unknown codes are reported by {@see status_is_unknown()} so they show up
	 * in the sync log and can be added here.
	 *
	 * @param array<string,mixed> $l Listing.
	 */
	public function is_available( array $l ): bool {
		return ! in_array( $this->status_code( $l ), self::UNAVAILABLE_CODES, true );
	}

	/**
	 * Normalised status code.
	 *
	 * @param array<string,mixed> $l Listing.
	 */
	public function status_code( array $l ): string {
		$code = (string) ( $this->pick( $l, 'status.code' ) ?? '' );
		return strtolower( str_replace( array( ' ', '-', '_' ), '', trim( $code ) ) );
	}

	/**
	 * True when the feed sent a status code this plugin does not recognise.
	 *
	 * @param array<string,mixed> $l Listing.
	 */
	public function status_is_unknown( array $l ): bool {
		$code = $this->status_code( $l );

		if ( '' === $code ) {
			return true;
		}

		return ! in_array( $code, self::AVAILABLE_CODES, true )
			&& ! in_array( $code, self::UNAVAILABLE_CODES, true );
	}

	/**
	 * Content hash used to skip unchanged cars. Excludes nothing — any feed
	 * change at all makes the car eligible for a re-sync of its owned fields.
	 *
	 * @param array<string,mixed> $l Listing.
	 */
	public function hash( array $l ): string {
		return md5( (string) wp_json_encode( $l ) );
	}
}
