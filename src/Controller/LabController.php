<?php
namespace App\Controller;

use Symfony\Component\Process\Process;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class LabController extends AbstractController
{
    private $kernel;

    public function __construct(KernelInterface $kernel)
    {
        $this->kernel = $kernel;
    }
    
    /**
     * @Route("/lab/start", name="lab_post", defaults={"_format"="xml"}, methods={"POST"})
     */
    public function startAction(Request $request)
    {
        if ('application/x-www-form-urlencoded' === $request->getContentType()) {
            //
        } elseif ('xml' === $request->getContentType()) {
            $lab = $request->getContent();
            # FIXME: Don't use sudo!
            $process = new Process([ $this->kernel->getProjectDir().'/scripts/start-lab.sh', $lab ]);
            try {
                $process->mustRun();
            } catch (ProcessFailedException $exception) {
                return new Response(
                    $this->renderView('response.xml.twig', [
                        'code' => $exception->getProcess()->getExitCode(),
                        'message' => $exception->getMessage()
                    ]),
                    500
                );
            }
            return new Response($process->getOutput() . '\nError output:\n\n' . $process->getErrorOutput());
        } else {
            return new Response(null, 415);
        }
    }

    /**
     * @Route("/lab/stop", name="lab_stop_post", defaults={"_format"="xml"}, methods={"POST"})
     */
    public function stopAction(Request $request)
    {
        if ('application/x-www-form-urlencoded' === $request->getContentType()) {
            //
        } elseif ('xml' === $request->getContentType()) {
            $lab = $request->getContent();
            # FIXME: Don't use sudo!
            $process = new Process([ $this->kernel->getProjectDir().'/scripts/stop-lab.sh', $lab ]);
            try {
                $process->mustRun();
            } catch (ProcessFailedException $exception) {
                return new Response(
                    $this->renderView('response.xml.twig', [
                        'code' => $exception->getProcess()->getExitCode(),
                        'message' => $exception->getMessage()
                    ]),
                    500
                );
            }
            return new Response($process->getOutput());
        } else {
            return new Response(null, 415, [ 'Content-Type' => 'text/plain' ]);
        }
    }
}
