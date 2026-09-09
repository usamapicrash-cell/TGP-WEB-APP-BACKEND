<div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;">
    <h2 style="color: #2d3748; margin-top: 0;">Dear {{ $customerName }},</h2>

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

    <p>Please review the appointment details. You can approve this time slot or submit a note to request a change directly below:</p>

    <!-- Dynamic Approval Button Call to Action -->
    @if(!empty($approvalLink))
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $approvalLink }}" 
               style="background-color: #6c5ce7; color: #ffffff; padding: 12px 28px; text-decoration: none; font-weight: bold; border-radius: 6px; display: inline-block; box-shadow: 0 4px 6px rgba(108, 92, 231, 0.2);">
               Confirm or Request Reschedule
            </a>
        </div>
        <p style="font-size: 13px; color: #718096; text-align: center;">
            If the button doesn't work, copy and paste this link in your browser:<br>
            <a href="{{ $approvalLink }}" style="color: #6c5ce7; word-break: break-all;">{{ $approvalLink }}</a>
        </p>
    @endif

    <p style="margin-top: 25px;">Thanks,<br><strong>The Glass People Team</strong></p>
</div>