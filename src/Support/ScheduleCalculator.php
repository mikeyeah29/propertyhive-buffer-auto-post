<?php
/**
 * Buffer scheduling calculation.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost\Support;

class ScheduleCalculator {
	/** Return the next local HH:MM occurrence as a UTC ISO-8601 value. */
	public function next_daily_iso( $time, \DateTimeZone $timezone, \DateTimeImmutable $now = null ) {
		$now   = $now ? $now : new \DateTimeImmutable( 'now', $timezone );
		$parts = explode( ':', (string) $time );
		$hour  = isset( $parts[0] ) ? min( 23, max( 0, (int) $parts[0] ) ) : 10;
		$min   = isset( $parts[1] ) ? min( 59, max( 0, (int) $parts[1] ) ) : 0;
		$next  = $now->setTime( $hour, $min, 0 );
		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}
		return $next->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s.000\Z' );
	}
}
