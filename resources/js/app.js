import './optimistic';

/**
 * "Now" formatted for a `datetime-local` input (`Y-m-d\TH:i`), in the given IANA timezone,
 * computed entirely in the browser so opening the "Pick date & time…" picker never waits on a
 * server round trip. The timezone itself is rendered once as `<html data-timezone>`.
 */
window.nowInDisplayTimezone = function (timeZone) {
    var parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(new Date());

    var get = function (type) {
        return parts.find(function (part) { return part.type === type; }).value;
    };

    // Some engines format midnight as "24" with hour12: false.
    var hour = get('hour') === '24' ? '00' : get('hour');

    return get('year') + '-' + get('month') + '-' + get('day') + 'T' + hour + ':' + get('minute');
};
