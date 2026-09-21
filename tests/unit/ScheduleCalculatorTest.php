<?php

use Homer\PropertyHiveBufferAutoPost\Support\ScheduleCalculator;
use PHPUnit\Framework\TestCase;

class ScheduleCalculatorTest extends TestCase {
	public function test_uses_same_day_when_time_is_still_ahead() {
		$zone = new DateTimeZone( 'Europe/London' );
		$now  = new DateTimeImmutable( '2026-09-21 09:00:00', $zone );
		$this->assertSame( '2026-09-21T13:30:00.000Z', ( new ScheduleCalculator() )->next_daily_iso( '14:30', $zone, $now ) );
	}

	public function test_uses_next_day_and_handles_dst() {
		$zone = new DateTimeZone( 'Europe/London' );
		$now  = new DateTimeImmutable( '2026-10-24 16:00:00', $zone );
		$this->assertSame( '2026-10-25T10:00:00.000Z', ( new ScheduleCalculator() )->next_daily_iso( '10:00', $zone, $now ) );
	}
}
