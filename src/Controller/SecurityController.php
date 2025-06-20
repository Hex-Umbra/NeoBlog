<?php

namespace App\Controller;

use App\Form\ForgotPasswordForm;
use App\Form\ResetPasswordForm;
use App\Repository\ArticlesRepository;
use App\Repository\CommentsRepository;
use App\Repository\UserRepository;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

class SecurityController extends AbstractController
{
    private $twig;
    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    #[Route(path: '/login', name: 'app.login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route(path: '/logout', name: 'app.logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route(path: "/forgot-password", name: "app.forgot_password")]
    public function forgotPassword(
        UserRepository $repo, 
        EntityManagerInterface $em, 
        Request $req, 
        EmailService $emailService): Response
    {
        // Creation of the form
        $form = $this->createForm(ForgotPasswordForm::class);
        $form->handleRequest($req);

        // Form Submission handler
        if ($form->isSubmitted() && $form->isValid()) {

            // Checking the email submitted is a valid email saved in the database
            $email = $form->get('Email')->getData();
            $user = $repo->findOneBy(["email" => $email]);

            // If The email is not valid then user does not exist
            if (!$user) {
            $this->addFlash("error", "The Email you're trying to use is invalid");
            return $this->redirectToRoute("app.login");
        }

            // Generation of the token for reset
            $token = Uuid::v4()->toRfc4122();
            $user->setToken($token);

            $em->flush();

            //  Generation of the link for reset
            $resetPasswordEmail = $this->generateUrl(
                "app.reset_password",
                ["token" => $user->getToken()],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            // Rendering page for the email
            $body = $this->twig->render("partials/email_reset_password.html.twig", [
                "url" => $resetPasswordEmail
            ]);

            // Send of the email
            $emailService->sendEmail(
                $email,
                "Reset Password",
                $body
            );

            $this->addFlash("success", "An email for reset password has been sent to your email");
            return $this->redirectToRoute("app.login");

        }

        return $this->render(
            "security/forgot-password.html.twig",
            [
                "form" => $form->createView(),
            ]
        );
    }

    #[Route(path: "/reset-password/{token}", name: "app.reset_password")]
    public function resetPassword(
        UserRepository $repo,
        EntityManagerInterface $em,
        Request $req,
        $token,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        // Checking if User exists, if not throw exception
        $user = $repo->findOneBy(["token" => $token]);
        if (!$user) {
            $this->addFlash("error", "The Token you're trying to use is invalid");
            return $this->redirectToRoute("app.login");
        }

        // Form Creation for Password Reset
        $form = $this->createForm(ResetPasswordForm::class);
        $form->handleRequest($req);

        // Form Submission
        if ($form->isSubmitted() && $form->isValid()) {
            // Getting the new password from the Form and hashing it
            $password = $form->get("Password")->getData();
            $hashedPassword = $passwordHasher->hashPassword($user, $password);
            $user->setPassword($hashedPassword);

            $user->setToken(null);

            $em->flush();

            $this->addFlash("success", "Your password was successfully reset");
            return $this->redirectToRoute("app.login");

        }

        return $this->render(
            "security/reset-password.html.twig",
            [
                "form" => $form,
            ]
        );
    }

    #[IsGranted("ROLE_USER")]
    #[Route(path: "/{id}/profile", name: "app.profile")]
    public function profile(ArticlesRepository $articlesRepo, CommentsRepository $commentsRepo): Response
    {
        $user = $this->getUser();
        $writtenArticles = $articlesRepo->findBy(["user" => $user]);
        $comments = $commentsRepo->findBy(["user" => $user], ["createdAt" => "DESC"]);

        return $this->render("user/profile.html.twig", [
            "articles" => $writtenArticles,
            "comments" => $comments
        ]);
    }
}
