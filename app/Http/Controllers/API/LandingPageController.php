<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;

class LandingPageController extends BaseController
{
    public function contactUs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed', $validator->errors(), 422);
        }

        try {
            // Log contact form submission (implement email sending if needed)
            \Log::info('Contact form submission', [
                'name' => $request->name,
                'email' => $request->email,
                'subject' => $request->subject,
            ]);

            // TODO: Send email to support team
            // Mail::send('emails.contact', $request->all(), function($message) {
            //     $message->to('support@investfolio.pk')
            //             ->subject('New Contact Form Submission');
            // });

            return $this->sendResponse([], 'Thank you for contacting us. We will get back to you soon.');
        } catch (\Exception $e) {
            return $this->sendError('Failed to submit contact form', [], 500);
        }
    }

    public function newsletterSignup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed', $validator->errors(), 422);
        }

        try {
            // Check if email already exists
            $exists = \DB::table('newsletter_subscribers')
                ->where('email', $request->email)
                ->exists();

            if ($exists) {
                return $this->sendResponse([], 'You are already subscribed to our newsletter.');
            }

            // Store subscriber
            \DB::table('newsletter_subscribers')->insert([
                'email' => $request->email,
                'created_at' => now(),
            ]);

            return $this->sendResponse([], 'Successfully subscribed to our newsletter!');
        } catch (\Exception $e) {
            return $this->sendError('Failed to subscribe', [], 500);
        }
    }

    public function waitlistSignup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'name' => 'nullable|string|max:255',
            'interest' => 'nullable|string|max:255', // e.g., 'bootcamp', 'premium-features'
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed', $validator->errors(), 422);
        }

        try {
            // Check if already on waitlist
            $exists = \DB::table('waitlist')
                ->where('email', $request->email)
                ->exists();

            if ($exists) {
                return $this->sendResponse([], 'You are already on the waitlist.');
            }

            // Add to waitlist
            \DB::table('waitlist')->insert([
                'email' => $request->email,
                'name' => $request->name,
                'interest' => $request->interest,
                'created_at' => now(),
            ]);

            return $this->sendResponse([], 'You have been added to the waitlist!');
        } catch (\Exception $e) {
            return $this->sendError('Failed to add to waitlist', [], 500);
        }
    }

    public function getStats()
    {
        try {
            $stats = [
                'active_users' => \DB::table('users')->count(),
                'listed_stocks' => \DB::table('stocks')->count(),
                'total_analyzed' => '₨50Cr+', // Placeholder
            ];

            return $this->sendResponse($stats, 'Stats retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Failed to get stats', [], 500);
        }
    }
}
