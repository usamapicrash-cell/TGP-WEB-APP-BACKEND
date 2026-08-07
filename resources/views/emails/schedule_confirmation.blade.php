<div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2>Dear {{ $customerName }},</h2>

    <p>Your <strong>{{ $type }}</strong> has been successfully scheduled. Please find the details below:</p>

    <div style="background-color: #f8f9fa; border-left: 4px solid #6c5ce7; padding: 15px; margin: 20px 0; border-radius: 4px;">
        <p style="margin: 5px 0;"><strong>Reference Code:</strong> {{ $referenceCode }}</p>
        <p style="margin: 5px 0;"><strong>Scheduled Date & Time:</strong> {{ \Carbon\Carbon::parse($scheduleDate)->format('F j, Y - g:i A') }}</p>
        
        @if(!empty($siteAddress))
            <p style="margin: 5px 0;"><strong>Location / Address:</strong> {{ $siteAddress }}</p>
        @endif

        @if(!empty($glazierName))
            <p style="margin: 5px 0;"><strong>Assigned Specialist:</strong> {{ $glazierName }}</p>
        @endif

        @if(!empty($notes))
            <p style="margin: 5px 0;"><strong>Additional Notes:</strong> {{ $notes }}</p>
        @endif
    </div>

    <p>Please review the schedule above and let us know if this date and time are convenient for you or if you need to make any adjustments.</p>

    <p>If everything looks good, no further action is required. We look forward to visiting your site.</p>

    <p style="margin-top: 25px;">Thanks,<br><strong>The Glass People Team</strong></p>
</div>