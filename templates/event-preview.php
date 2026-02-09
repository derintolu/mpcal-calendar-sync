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

// Get add-to-calendar data
$event_group_id = $template_event->get_event_group_id();
$atc_title      = get_post_meta( $event_group_id, '_mpcal_atc_title', true );
$atc_start      = get_post_meta( $event_group_id, '_mpcal_atc_start', true );
$atc_end        = get_post_meta( $event_group_id, '_mpcal_atc_end', true );
$atc_allday     = get_post_meta( $event_group_id, '_mpcal_atc_allday', true );
$atc_timezone   = get_post_meta( $event_group_id, '_mpcal_atc_timezone', true );
$atc_location   = get_post_meta( $event_group_id, '_mpcal_atc_location', true );
$atc_desc       = get_post_meta( $event_group_id, '_mpcal_atc_description', true );
$has_atc        = ! empty( $atc_title ) && ! empty( $atc_start );
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

	<?php if ( $has_atc ) :
		$start_date = date( 'Y-m-d', strtotime( $atc_start ) );
		$end_date   = date( 'Y-m-d', strtotime( $atc_end ) );
		$start_time = ( '1' !== $atc_allday ) ? date( 'H:i', strtotime( $atc_start ) ) : '';
		$end_time   = ( '1' !== $atc_allday ) ? date( 'H:i', strtotime( $atc_end ) ) : '';
	?>
	<div class="mpcal-event-preview__row mpcal-event-preview__addtocal">
		<div class="mpcal-event-preview__row-icon">
			<i class="mcal-icon mcal-icon-calendar"></i>
		</div>
		<div class="mpcal-event-preview__row-content">
			<add-to-calendar-button
				name="<?php echo esc_attr( $atc_title ); ?>"
				startDate="<?php echo esc_attr( $start_date ); ?>"
				endDate="<?php echo esc_attr( $end_date ); ?>"
				<?php if ( ! empty( $start_time ) ) : ?>startTime="<?php echo esc_attr( $start_time ); ?>" endTime="<?php echo esc_attr( $end_time ); ?>"<?php endif; ?>
				timeZone="<?php echo esc_attr( $atc_timezone ?: 'America/Los_Angeles' ); ?>"
				<?php if ( ! empty( $atc_location ) ) : ?>location="<?php echo esc_attr( $atc_location ); ?>"<?php endif; ?>
				<?php if ( ! empty( $atc_desc ) ) : ?>description="<?php echo esc_attr( wp_strip_all_tags( substr( $atc_desc, 0, 500 ) ) ); ?>"<?php endif; ?>
				options="'Apple','Google','Outlook.com','Microsoft365','Yahoo','iCal'"
				lightMode="bodyScheme"
				size="3"
			></add-to-calendar-button>
		</div>
	</div>
	<?php endif; ?>
</div>
