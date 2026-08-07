@component('mail::message')
# Dear {{ $job->customer_name ?? 'Valued Customer' }},

Your **{{ $type }}** has been successfully scheduled. Please find the details below:

@component('mail::panel')
**Reference Code:** {{ $job->reference_code }}  
**Scheduled Date & Time:** {{ \Carbon\Carbon::parse($scheduleDate)->format('F j, Y - g:i A') }}  
@if(!empty($job->site_address))
**Location / Address:** {{ $job->site_address }}  
@endif
@if($job->glazier)
**Assigned Specialist:** {{ $job->glazier->name }}  
@endif
@if(!empty($notes))
**Additional Notes:** {{ $notes }}  
@endif
@endcomponent

Please review the schedule above and let us know if this date and time are convenient for you or if you need to make any adjustments.

If everything looks good, no further action is required. We look forward to visiting your site.

Thanks,  
**{{ config('app.name') }} Team**
@endcomponent