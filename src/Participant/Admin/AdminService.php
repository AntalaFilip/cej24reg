<?php

declare(strict_types=1);

namespace kissj\Participant\Admin;

use kissj\FlashMessages\FlashMessagesInterface;
use kissj\Mailer\PhpMailerWrapper;
use kissj\Participant\Participant;
use kissj\Participant\ParticipantRepository;
use kissj\Payment\PaymentRepository;
use kissj\Payment\PaymentService;
use kissj\Payment\PaymentStatus;
use kissj\User\UserRepository;
use kissj\User\UserStatus;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class AdminService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ParticipantRepository $participantRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly PaymentService $paymentService,
        private readonly PhpMailerWrapper $mailer,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isPaymentTransferPossible(
        ?Participant $participantFrom,
        ?Participant $participantTo,
        FlashMessagesInterface $flash // use for outputting friendly user messages what is wrong
    ): bool {
        $isPossible = true;

        if ($participantFrom === null || $participantTo === null) {
            $flash->warning($this->translator->trans('flash.warning.nullParticipants'));

            return false; // no point to compare further
        }

        if (!$participantFrom instanceof $participantTo) {
            $flash->warning($this->translator->trans('flash.warning.differentParticipants'));
            $isPossible = false;
        }

        if (!$participantFrom->getUserButNotNull()->status->isPaidOrCancelled()) {
            $flash->warning($this->translator->trans('flash.warning.notPaid'));
            $isPossible = false;
        }

        if ($participantTo->getUserButNotNull()->status->isPaidOrCancelled()) {
            $flash->warning($this->translator->trans('flash.warning.isPaid'));
            $isPossible = false;
        }

        if ($participantFrom->scarf !== $participantTo->scarf) {
            $flash->info($this->translator->trans('flash.info.differentScarfs'));
        }

        return $isPossible;
    }

    /**
     * Transfer payment From To
     * Cancel all waiting payments To and send email about it
     * Set From as open and send him email about payment transfer
     * Set To as paid and send him email about payment transfer
     * Handle scarf correction on To
     */
    public function transferPayment(Participant $participantFrom, Participant $participantTo): void
    {
        $correctPayment = null;
        foreach ($participantFrom->payment as $payment) {
            if ($payment->status === PaymentStatus::Paid) {
                $correctPayment = $payment;
            }
        }

        if ($correctPayment === null) {
            throw new \RuntimeException('Payment marked as paid was not found with participant marked as paid');
        }

        foreach ($participantTo->payment as $payment) {
            if ($payment->status === PaymentStatus::Waiting) {
                $this->paymentService->cancelPayment($payment);
                $this->mailer->sendCancelledPayment(
                    $participantTo,
                    $this->translator->trans(
                        'email.text.paymentTransfered',
                        [],
                        null,
                        'cs'
                    ) // TODO add preference according to participant
                );
            }
        }

        // handle scarf correction
        if ($participantFrom->scarf !== $participantTo->scarf) {
            $participantTo->scarf = $participantFrom->scarf;
        }

        $correctPayment->participant = $participantTo;

        $userFrom = $participantFrom->getUserButNotNull();
        $userFrom->status = UserStatus::Open;

        $userTo = $participantTo->getUserButNotNull();
        $userTo->status = UserStatus::Paid;

        $this->paymentRepository->persist($correctPayment);
        $this->participantRepository->persist($participantTo);
        $this->userRepository->persist($userFrom);
        $this->userRepository->persist($userTo);

        $this->mailer->sendRegistrationPaid($participantTo);
        $this->mailer->sendPaymentTransferedFromYou($participantFrom);

        $this->logger->info('Tranfered payment ID ' . $correctPayment->id
            . ' from participant ID ' . $userFrom->id . ' to participant ID ' . $userTo->id);
    }
}
