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

// Get add-to-calendar links
$event_group_id = $template_event->get_event_group_id();
$atc_google     = get_post_meta( $event_group_id, '_mpcal_atc_google', true );
$atc_outlook    = get_post_meta( $event_group_id, '_mpcal_atc_outlook', true );
$atc_office365  = get_post_meta( $event_group_id, '_mpcal_atc_office365', true );
$atc_yahoo      = get_post_meta( $event_group_id, '_mpcal_atc_yahoo', true );
$atc_ics        = get_post_meta( $event_group_id, '_mpcal_atc_ics', true );
$has_atc        = ! empty( $atc_google ) || ! empty( $atc_ics );
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
		<div class="mpcal-event-preview__row-icon">
			<i class="mcal-icon mcal-icon-calendar"></i>
		</div>
		<div class="mpcal-event-preview__row-content">
			<div class="mpcal-atc-buttons">
				<?php if ( ! empty( $atc_google ) ) : ?>
					<a href="<?php echo esc_url( $atc_google ); ?>" target="_blank" rel="noopener noreferrer" class="mpcal-atc-btn mpcal-atc-btn--google" title="<?php esc_attr_e( 'Google Calendar', 'mpcal-ics-sync' ); ?>">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M19.5 3h-3V1.5h-1.5V3h-6V1.5H7.5V3h-3C3.675 3 3 3.675 3 4.5v15c0 .825.675 1.5 1.5 1.5h15c.825 0 1.5-.675 1.5-1.5v-15c0-.825-.675-1.5-1.5-1.5zm0 16.5h-15V8.25h15v11.25z"/></svg>
					</a>
				<?php endif; ?>
				<?php if ( ! empty( $atc_outlook ) ) : ?>
					<a href="<?php echo esc_url( $atc_outlook ); ?>" target="_blank" rel="noopener noreferrer" class="mpcal-atc-btn mpcal-atc-btn--outlook" title="<?php esc_attr_e( 'Outlook', 'mpcal-ics-sync' ); ?>">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M7 3h10a4 4 0 0 1 4 4v10a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4zm5 4a5 5 0 1 0 0 10 5 5 0 0 0 0-10z"/></svg>
					</a>
				<?php endif; ?>
				<?php if ( ! empty( $atc_office365 ) ) : ?>
					<a href="<?php echo esc_url( $atc_office365 ); ?>" target="_blank" rel="noopener noreferrer" class="mpcal-atc-btn mpcal-atc-btn--office365" title="<?php esc_attr_e( 'Office 365', 'mpcal-ics-sync' ); ?>">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M3 5.5L10.5 4v16L3 18.5V5.5zm9-2.5l9 1.5v15l-9 1.5V3z"/></svg>
					</a>
				<?php endif; ?>
				<?php if ( ! empty( $atc_yahoo ) ) : ?>
					<a href="<?php echo esc_url( $atc_yahoo ); ?>" target="_blank" rel="noopener noreferrer" class="mpcal-atc-btn mpcal-atc-btn--yahoo" title="<?php esc_attr_e( 'Yahoo', 'mpcal-ics-sync' ); ?>">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/></svg>
					</a>
				<?php endif; ?>
				<?php if ( ! empty( $atc_ics ) ) : ?>
					<a href="<?php echo esc_url( $atc_ics ); ?>" target="_blank" rel="noopener noreferrer" class="mpcal-atc-btn mpcal-atc-btn--ics" title="<?php esc_attr_e( 'Download ICS', 'mpcal-ics-sync' ); ?>">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
					</a>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php endif; ?>
</div>

<style>
.mpcal-atc-buttons {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}
.mpcal-atc-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border-radius: 4px;
	text-decoration: none;
	transition: opacity 0.2s;
}
.mpcal-atc-btn:hover {
	opacity: 0.8;
}
.mpcal-atc-btn--google {
	background: #4285f4;
	color: #fff;
}
.mpcal-atc-btn--outlook {
	background: #0078d4;
	color: #fff;
}
.mpcal-atc-btn--office365 {
	background: #d83b01;
	color: #fff;
}
.mpcal-atc-btn--yahoo {
	background: #720e9e;
	color: #fff;
}
.mpcal-atc-btn--ics {
	background: #333;
	color: #fff;
}
</style>
