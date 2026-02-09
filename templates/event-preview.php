<?php
use Motopress_Calendar\Motopress_Calendar;
use Motopress_Calendar\Templates\Templates_Helper;

/**
 * Template: Event Preview (with Add to Calendar buttons)
 *
 * @var \Motopress_Calendar\Core\Event $template_event
 */

$wp_date_format = Motopress_Calendar::get_settings_api()->get_wp_date_format();
$wp_time_format = Motopress_Calendar::get_settings_api()->get_wp_time_format();

$event_start = wp_date(
	$wp_date_format,
	$template_event->get_start_in_wp_timezone()->getTimestamp()
);

$event_end = wp_date(
	$wp_date_format,
	$template_event->get_end_in_wp_timezone()->getTimestamp()
);

if ( ! $template_event->is_all_day_event() ) {

	if ( $event_start === $event_end ) {

		$event_start = wp_date( 'l, F j', $template_event->get_start_in_wp_timezone()->getTimestamp() ) . ' ⋅';
		$event_end   = '';
	} else {
		$event_end .= ' ';
	}

	$event_start .= ' ' . wp_date(
		$wp_time_format,
		$template_event->get_start_in_wp_timezone()->getTimestamp()
	);

	$event_end .= wp_date(
		$wp_time_format,
		$template_event->get_end_in_wp_timezone()->getTimestamp()
	);
}

$time_string = $event_start . ' - ' . $event_end;

if ( $template_event->is_all_day_event() && $event_start === $event_end ) {

		$time_string = wp_date( 'l, F j', $template_event->get_start_in_wp_timezone()->getTimestamp() );
}

// Build add-to-calendar links dynamically from event data
$atc_title      = $template_event->get_event_group()->get_event_title();
$atc_desc       = $template_event->get_event_group()->get_event_description();
$atc_start      = $template_event->get_start_in_wp_timezone();
$atc_end        = $template_event->get_end_in_wp_timezone();
$atc_allday     = $template_event->is_all_day_event();
$atc_location   = $template_event->get_event_location() ? $template_event->get_event_location()->get_title() : '';

$atc_start_utc = clone $atc_start;
$atc_start_utc->setTimezone( new \DateTimeZone( 'UTC' ) );
$atc_end_utc = clone $atc_end;
$atc_end_utc->setTimezone( new \DateTimeZone( 'UTC' ) );

$atc_title_enc = rawurlencode( $atc_title );
$atc_desc_enc  = rawurlencode( substr( wp_strip_all_tags( $atc_desc ), 0, 1000 ) );
$atc_loc_enc   = rawurlencode( $atc_location );

if ( $atc_allday ) {
	$g_dates = $atc_start->format( 'Ymd' ) . '/' . ( clone $atc_end )->modify( '+1 day' )->format( 'Ymd' );
	$o_start = $atc_start->format( 'Y-m-d' );
	$o_end   = $atc_end->format( 'Y-m-d' );
} else {
	$g_dates = $atc_start_utc->format( 'Ymd\THis\Z' ) . '/' . $atc_end_utc->format( 'Ymd\THis\Z' );
	$o_start = $atc_start_utc->format( 'Y-m-d\TH:i:s\Z' );
	$o_end   = $atc_end_utc->format( 'Y-m-d\TH:i:s\Z' );
}

$atc_google    = 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . $atc_title_enc . '&dates=' . $g_dates . ( $atc_desc ? '&details=' . $atc_desc_enc : '' ) . ( $atc_location ? '&location=' . $atc_loc_enc : '' );
$atc_office365 = 'https://outlook.office.com/calendar/0/deeplink/compose?path=/calendar/action/compose&rru=addevent&subject=' . $atc_title_enc . '&startdt=' . rawurlencode( $o_start ) . '&enddt=' . rawurlencode( $o_end ) . ( $atc_allday ? '&allday=true' : '' ) . ( $atc_desc ? '&body=' . $atc_desc_enc : '' ) . ( $atc_location ? '&location=' . $atc_loc_enc : '' );
$atc_outlook   = 'https://outlook.live.com/calendar/0/deeplink/compose?path=/calendar/action/compose&rru=addevent&subject=' . $atc_title_enc . '&startdt=' . rawurlencode( $o_start ) . '&enddt=' . rawurlencode( $o_end ) . ( $atc_allday ? '&allday=true' : '' ) . ( $atc_desc ? '&body=' . $atc_desc_enc : '' ) . ( $atc_location ? '&location=' . $atc_loc_enc : '' );
$atc_yahoo     = 'https://calendar.yahoo.com/?v=60&title=' . $atc_title_enc . '&st=' . ( $atc_allday ? $atc_start->format( 'Ymd' ) : $atc_start_utc->format( 'Ymd\THis\Z' ) ) . '&et=' . ( $atc_allday ? $atc_end->format( 'Ymd' ) : $atc_end_utc->format( 'Ymd\THis\Z' ) ) . ( $atc_allday ? '&dur=allday' : '' ) . ( $atc_desc ? '&desc=' . $atc_desc_enc : '' ) . ( $atc_location ? '&in_loc=' . $atc_loc_enc : '' );

$ics_params = array( 'title' => $atc_title, 'start' => $atc_allday ? $atc_start->format( 'Y-m-d' ) : $atc_start_utc->format( 'Y-m-d\TH:i:s\Z' ), 'end' => $atc_allday ? $atc_end->format( 'Y-m-d' ) : $atc_end_utc->format( 'Y-m-d\TH:i:s\Z' ) );
if ( $atc_desc )     $ics_params['description'] = substr( wp_strip_all_tags( $atc_desc ), 0, 500 );
if ( $atc_location ) $ics_params['location'] = $atc_location;
if ( $atc_allday )   $ics_params['allDay'] = 'true';
$atc_ics = 'https://calndr.link/d/event/?' . http_build_query( $ics_params );
$has_atc = true;
?>

<div class="mpcal-event-preview">
	<?php if ( $template_event->get_event_group()->has_event_thumbnail() ) : ?>
		<div class="mpcal-event-preview__row mpcal-event-preview__thumbnail">
			<?php echo wp_kses_post( $template_event->get_event_group()->get_event_thumbnail_html() ); ?>
		</div>
	<?php endif; ?>
	<div class="mpcal-event-preview__row mpcal-event-preview__header">
		<div class="mpcal-event-preview__row-icon">
			<div class="mpcal-event-preview__color" style="background-color: <?php echo esc_attr( $template_event->get_event_group()->get_color() ); ?>;"></div>
		</div>
		<div class="mpcal-event-preview__row-content">
			<span class="mpcal-event-preview__title"><?php echo esc_html( $template_event->get_event_group()->get_event_title() ); ?></span>
			<span class="mpcal-event-preview__time"><?php echo esc_html( $time_string ); ?></span>
		</div>
	</div>

	<?php if ( null !== $template_event->get_event_location() ) : ?>
		<div class="mpcal-event-preview__row mpcal-event-preview__location">
			<div class="mpcal-event-preview__row-icon">
				<i class="mcal-icon mcal-icon-map"></i>
			</div>
			<div class="mpcal-event-preview__row-content">
				<?php echo esc_html( $template_event->get_event_location()->get_title() ); ?>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( null !== $template_event->get_event_organizer() ) : ?>
		<div class="mpcal-event-preview__row mpcal-event-preview__organizer">
			<div class="mpcal-event-preview__row-icon">
				<i class="mcal-icon mcal-icon-user"></i>
			</div>
			<div class="mpcal-event-preview__row-content">
				<?php echo esc_html( $template_event->get_event_organizer()->get_name() ); ?>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $template_event->get_event_group()->get_event_description() ) ) : ?>
		<div class="mpcal-event-preview__row mpcal-event-preview__description">
			<div class="mpcal-event-preview__row-icon">
				<i class="mcal-icon mcal-icon-text"></i>
			</div>
			<div class="mpcal-event-preview__row-content">
				<?php echo wp_kses_post( $template_event->get_event_group()->get_event_description() ); ?>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( ! $template_event->get_event_group()->get_event_page_type()->is_none() ) : ?>
	<div class="mpcal-event-preview__row">
		<div class="mpcal-event-preview__row-icon">
		</div>
		<div class="mpcal-event-preview__row-content">
			<a class="button"
				title="<?php echo esc_attr( Templates_Helper::get_event_title( $template_event ) ); ?>"
				href="<?php echo esc_url( $template_event->get_event_page_url() ); ?>"
			><?php esc_html_e( 'View Event', 'motopress-calendar' ); ?></a>
		</div>
	</div>
	<?php endif; ?>

	<?php if ( $has_atc ) : ?>
	<div class="mpcal-event-preview__row mpcal-event-preview__addtocal">
		<div class="mpcal-event-preview__row-content">
			<style>
			.mpcal-atc-wrap{position:relative;display:inline-block;margin:.5em 0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,Ubuntu,sans-serif}
			.mpcal-atc-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;background:#1a73e8;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;transition:background .2s,box-shadow .2s;box-shadow:0 1px 3px rgba(0,0,0,.12)}
			.mpcal-atc-btn:hover{background:#1557b0;box-shadow:0 2px 6px rgba(0,0,0,.2)}
			.mpcal-atc-btn svg{flex-shrink:0}
			.mpcal-atc-chevron{transition:transform .2s}
			.mpcal-atc-wrap.open .mpcal-atc-chevron{transform:rotate(180deg)}
			.mpcal-atc-dropdown{display:none;position:absolute;bottom:calc(100% + 6px);left:0;min-width:220px;background:#fff;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.15);z-index:1000;overflow:hidden;border:1px solid rgba(0,0,0,.08)}
			.mpcal-atc-wrap.open .mpcal-atc-dropdown{display:block;animation:mpcal-fade-in .15s ease}
			@keyframes mpcal-fade-in{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
			.mpcal-atc-dropdown a{display:flex;align-items:center;gap:12px;padding:11px 16px;color:#333;text-decoration:none;font-size:14px;transition:background .12s}
			.mpcal-atc-dropdown a:hover{background:#f5f7fa}
			.mpcal-atc-dropdown a:not(:last-child){border-bottom:1px solid #f0f0f0}
			.mpcal-atc-icon{display:flex;align-items:center;justify-content:center;width:24px;height:24px;flex-shrink:0}
			.mpcal-atc-icon svg{display:block}
			</style>
			<div class="mpcal-atc-wrap">
				<button type="button" class="mpcal-atc-btn" onclick="this.parentElement.classList.toggle('open')">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
					<span>Add to Calendar</span>
					<svg class="mpcal-atc-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
				</button>
				<div class="mpcal-atc-dropdown">
					<?php if ( ! empty( $atc_google ) ) : ?><a href="<?php echo esc_url( $atc_google ); ?>" target="_blank" rel="noopener"><span class="mpcal-atc-icon"><svg width="18" height="18" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18A11.96 11.96 0 0 0 1 12c0 1.94.46 3.77 1.18 5.07l3.66-2.84z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg></span><span>Google Calendar</span></a><?php endif; ?>
					<?php if ( ! empty( $atc_office365 ) ) : ?><a href="<?php echo esc_url( $atc_office365 ); ?>" target="_blank" rel="noopener"><span class="mpcal-atc-icon"><svg width="18" height="18" viewBox="0 0 24 24"><path d="M11.5 3L2 7v10l9.5 4L22 17V7L11.5 3z" fill="#D83B01"/><path d="M11.5 3L2 7v10l9.5 4V3z" fill="#EA3E23"/><path d="M11.5 3v18L22 17V7L11.5 3z" fill="#FF6A33"/></svg></span><span>Microsoft 365</span></a><?php endif; ?>
					<?php if ( ! empty( $atc_outlook ) ) : ?><a href="<?php echo esc_url( $atc_outlook ); ?>" target="_blank" rel="noopener"><span class="mpcal-atc-icon"><svg width="18" height="18" viewBox="0 0 24 24"><path d="M24 7.387v10.478c0 .23-.08.424-.238.576a.806.806 0 0 1-.588.234h-8.42v-6.56l1.678 1.253a.546.546 0 0 0 .672 0L24 7.387z" fill="#0072C6"/><path d="M17.204 5.108l-1.278.96a.546.546 0 0 1-.672 0l-1.278-.96h-.007v2.58l1.617 1.21a.546.546 0 0 0 .672 0L24 2.86V2.1c0-.23-.08-.424-.238-.576A.806.806 0 0 0 23.174 1.29H14.753v3.818h2.451z" fill="#0072C6"/><path d="M8.758 8.633c-.626-.417-1.41-.626-2.35-.626-.96 0-1.75.213-2.37.638-.618.426-.93 1.047-.93 1.863v3.998c0 .824.315 1.45.946 1.877.63.426 1.416.64 2.354.64.94 0 1.72-.214 2.343-.64.622-.428.934-1.053.934-1.877v-3.998c0-.808-.31-1.43-.927-1.875z" fill="#0072C6"/><path d="M7.542 15.29c-.213.33-.558.495-1.034.495-.475 0-.822-.164-1.04-.492-.22-.328-.328-.818-.328-1.47v-4.09c0-.665.113-1.16.34-1.485.226-.326.574-.49 1.04-.49.46 0 .8.165 1.02.496.22.33.33.823.33 1.48v4.088c0 .652-.11 1.142-.328 1.47z" fill="#fff"/><path d="M14.004 5.11V18.4H1.588C1.152 18.4.8 18.252.53 17.955.176 17.576 0 17.133 0 16.625V4.565c0-.508.176-.95.53-1.33.27-.296.622-.444 1.057-.444h10.52c.648 0 1.214.244 1.698.732.16.166.2.34.2.52v1.065z" fill="#0072C6"/></svg></span><span>Outlook.com</span></a><?php endif; ?>
					<?php if ( ! empty( $atc_yahoo ) ) : ?><a href="<?php echo esc_url( $atc_yahoo ); ?>" target="_blank" rel="noopener"><span class="mpcal-atc-icon"><svg width="18" height="18" viewBox="0 0 24 24"><rect width="24" height="24" fill="#6001D2" rx="4"/><path d="M5.5 5l3.8 7.4L13 5h3l-5.7 10.5V19h-3v-3.5L1.5 5h4zm11.5 0h3v14h-3V5z" fill="#fff"/></svg></span><span>Yahoo Calendar</span></a><?php endif; ?>
					<?php if ( ! empty( $atc_ics ) ) : ?><a href="<?php echo esc_url( $atc_ics ); ?>" target="_blank" rel="noopener"><span class="mpcal-atc-icon"><svg width="18" height="18" viewBox="0 0 24 24"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z" fill="#333"/></svg></span><span>Apple / iCal</span></a><?php endif; ?>
				</div>
			</div>
			<script>document.addEventListener("click",function(e){document.querySelectorAll(".mpcal-atc-wrap.open").forEach(function(w){if(!w.contains(e.target))w.classList.remove("open")})});</script>
		</div>
	</div>
	<?php endif; ?>
</div>
