<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Admin\S3Controller;
use Aws\Acm\AcmClient;
use Aws\CloudFront\CloudFrontClient;
use Aws\CloudFormation\CloudFormationClient;
use Aws\Exception\AwsException;
use App\Models\Certificate;
use App\Models\LandingPage;
use Session;
use DB;

class AwsCertificateController extends Controller
{

    protected $acmClient;
    protected $cloudfront;

    public function __construct()
    {
        $this->acmClient = new AcmClient([
            'version' => 'latest',
            'region'  => config('services.aws2.region'),
            'credentials' => [
                'key'    => config('services.aws2.key'),
                'secret' => config('services.aws2.secret'),
            ],
        ]);

        $this->cloudfront = new CloudFrontClient([
            'version' => 'latest',
            'region'  => config('services.aws1.region'),
            'credentials' => [
                'key'    => config('services.aws2.key'),
                'secret' => config('services.aws2.secret'),
            ],
        ]);
        //$this->getDistributionIdByCname();
    }

    public function index() {
        $certificates = Certificate::with('user:id,name')->get();
        return view('client-domains', compact('certificates'));
    }

    public function createSSL(Request $request)
    {
        $validated = $request->validate([
            'domain_name' => 'required|regex:/^([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,6}$/',
            'client-list' => 'required',
        ], [
            'client-list.required' => 'Please select a client from the list.',
        ]);
        $domainName = $validated['domain_name'];

        try {

            $existingCertificate = Certificate::where('domain_name', $domainName)->first();

            if ($existingCertificate) {
                return response()->json([
                    'message' => 'Certificate already requested for this domain.',
                    'certificate_arn' => $existingCertificate->certificate_id,
                    'status' => $existingCertificate->status,
                    'cname' => json_decode($existingCertificate->cname),
                    'user_id' => $existingCertificate->user_id,
                    'id'=> $existingCertificate->id
                ]);
            }

            $result = $this->acmClient->requestCertificate([
                'DomainName' => $domainName,
                'ValidationMethod' => 'DNS',
            ]);

            $certificateArn = $result['CertificateArn'];
            sleep(15);

            $validation = $this->getValidationInstructions($this->acmClient, $certificateArn);

            if(!empty($validation))
                $cname = json_encode($validation);
            else $cname = NULL;

            $attributes = [
                'domain_name' => $domainName
            ];
            $values = [
                'user_id' => $request->input('client-list'),
                'status' => 'PENDING_VALIDATION',
                'cname' => $cname,
                'certificate_id' => $certificateArn,
            ];

            $certificate = Certificate::firstOrCreate($attributes, $values);

            return response()->json([
                'message' => 'SSL certificate requested successfully.',
                'certificate_arn' => $certificateArn,
                'validation_instructions' => $validation,
                'id'=> $certificate->id
            ]);

        } catch (AwsException $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function viewSSL($id) {
        $certificate = Certificate::find($id);
        $cstatus = '';
        if($certificate) {
            $certificate->load('user:id,name');
            preg_match('/([a-zA-Z0-9-]+\.[a-zA-Z]{2,})$/', $certificate->domain_name, $domain);
            $landingPages = LandingPage::where('domain',$domain[0])->get();
            if($certificate->status != 'ISSUED') {
                $cstatus = $this->checkCertificateStatus($certificate->certificate_id);
                $issuedAt =  isset($cstatus['IssuedAt']) ? $cstatus['IssuedAt']: NULL ;
                $expire  =  isset($cstatus['NotAfter']) ? $cstatus['NotAfter']: NULL;
                Certificate::where('id', $id)->update(['status' => $cstatus['Status'], 'issued_at'=>$issuedAt,'renewal_at'=>$expire]);
                $certificate->status = $cstatus['Status'];
            }

            if(!empty($certificate->cname)) $certificate->cname = json_decode($certificate->cname);
            return view('view-client-domains', compact('certificate', 'landingPages'));
        }
        Session::flash( 'msg', 'Domain not found / Deleted successfully' );
        Session::flash( 'alert-class', 'bg-danger' );

        return redirect()->route('client-domains');
    }

    private function getValidationInstructions(AcmClient $acmClient, $certificateArn)
    {
        try {
            $certificateDetails = $acmClient->describeCertificate([
                'CertificateArn' => $certificateArn,
            ]);

            $validationOptions = $certificateDetails['Certificate']['DomainValidationOptions'];

            $instructions = [];
            foreach ($validationOptions as $option) {
                if ($option['ValidationMethod'] === 'DNS' && !empty($option['ResourceRecord'])) {
                    $instructions[] = [
                        'Name' => $option['ResourceRecord']['Name'],
                        'Type' => $option['ResourceRecord']['Type'],
                        'Value' => $option['ResourceRecord']['Value'],
                    ];
                }
            }

            return $instructions;

        } catch (AwsException $e) {
            return ['error' => 'Failed to get validation instructions: ' . $e->getMessage()];
        }
    }

    public function checkCertificateStatus($certificateArn)
    {
       try {
            $result = $this->acmClient->describeCertificate([
                'CertificateArn' => $certificateArn,
            ]);
            $status = $result['Certificate'];
            return $status;
        } catch (AwsException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    public function getCertificates()
    {

        try {
            $result = $this->acmClient->listCertificates([
                'CertificateStatuses' => ['ISSUED'], // You can filter by status like ISSUED, EXPIRED, etc.
            ]);
            $certificates = [];
            foreach ($result['CertificateSummaryList'] as $certificate) {
                $certificates[] = [
                    'DomainName' => $certificate['DomainName'],
                    'CertificateId' => $certificate['CertificateArn'],
                    'Status' => $certificate['Status'],
                    'IssuedAt' => isset($certificate['IssuedAt']) ? $certificate['IssuedAt']: '',
                    'RenewalAt' => $certificate['NotAfter']
                ];
            }
            return response()->json($certificates);

        } catch (AwsException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getDistributionIdByCname()
    {
        $result = $this->cloudfront->listDistributions();
        $id = [];
        $cert = Certificate::all();
        foreach($cert as $c) {
            foreach ($result['DistributionList']['Items'] as $distribution) {
                if (in_array($c->domain_name, $distribution['Aliases']['Items'])) {
                    $id[] = array($c->domain_name, $distribution['Id']);
                    Certificate::where('domain_name', $c->domain_name)
                    ->update(['cf_id' => $distribution['Id']]);
                }
            }
        }
        return json_encode($id);
    }

    public function setupDNS(Request $request) {

        $cert = Certificate::find($request->id);

        $client = new CloudFormationClient([
            'region' => config('services.aws2.region'),
            'version' => 'latest',
            'credentials' => [
                'key'    => config('services.aws2.key'),
                'secret' => config('services.aws2.secret'),
            ],
        ]);

        if($cert) {
            $stackName = str_replace('.', '-', $cert->domain_name);
            $templateBody = file_get_contents(public_path('assets/dns-setup.yml'));

            try {
                $result = $client->createStack([
                    'StackName' => $stackName,
                    'TemplateBody' => $templateBody,
                    'Parameters' => [
                        [
                            'ParameterKey' => 'BucketName',
                            'ParameterValue' => $cert->domain_name
                        ],
                        [
                            'ParameterKey' => 'CertificateArn',
                            'ParameterValue' => $cert->certificate_id
                        ]
                    ]
                ]);

                Certificate::where('id', $request->id)->update(["stack_id"=>$result['StackId'], "domain_status"=>2]);

                return response()->json([
                    'message' => 'Stack creation initiated successfully.',
                    'stack_id' => $result['StackId'],
                ]);

            } catch (AwsException $e) {
                return response()->json(['message' => $e->getMessage()]);
            }
        }
        else {
            return response()->json(['message' => 'Unable to find the certificate']);
        }
    }

    public function checkStackStatus(Request $request)
    {
        $cert = Certificate::find($request->id);
        $stackId = $cert->stack_id;
        $client = new CloudFormationClient([
            'region' => config('services.aws2.region'),
            'version' => 'latest',
            'credentials' => [
                'key'    => config('services.aws2.key'),
                'secret' => config('services.aws2.secret'),
            ],
        ]);

        try {
            $result = $client->describeStacks([
                'StackName' => $stackId,
            ]);

            $stackStatus = $result['Stacks'][0]['StackStatus'];

            if ($stackStatus === 'CREATE_COMPLETE') {
                $outputs = $result['Stacks'][0]['Outputs'];
                $cf_id = $cf_domain = '';
                if(!empty($outputs)) {
                    foreach($outputs as $output) {
                        if($output['OutputKey'] == 'CloudFrontID') $cf_id = $output['OutputValue'];
                        if($output['OutputKey'] == 'CloudFrontURL') $cf_domain = $output['OutputValue'];
                    }
                    Certificate::where('id', $request->id)
                    ->update(['cf_domain'=>$cf_domain, 'cf_id'=>$cf_id, 'domain_status'=>3]);
                }
                return response()->json([
                    'stack_status' => $stackStatus,
                    'message' => 'success',
                    'outputs' => $outputs,
                    'status'=> $cert->domain_status
                ]);
            }
            return response()->json([
                'stack_status' => $stackStatus,
                'status'=> $cert->domain_status,
                'message' => $stackStatus.'- DNS Setup is in progress... Last Updated '.Date('m/d/y h:i A'),
            ]);
        } catch (AwsException $e) {
            return response()->json(['message' => $e->getMessage(), 'status'=> $cert->domain_status]);
        }
    }
    public function deleteDomain($id, S3Controller $s3) {
        $cert = Certificate::find($id);
        if(!$cert)
            return response()->json(['message' => 'Delete Mode: Certificate not found', 'status'=> $cert->domain_status]);
        if(!empty($cert->stack_id)) {
            Certificate::where('id', $id)->update(['domain_status'=> 4]);
            try {
                $empty = $s3->emptyBucket($cert->domain_name);
                if ($empty != 'success' && $empty != 'not found')
                    throw new \Exception('Unable to delete the storage: ' . $empty);
                $delStack = $this->deleteStack($cert->stack_id);
                return response()->json(['message' => $empty.'<br>'.$delStack, 'status'=> 4]);
            }
            catch (\Exception $e) {
                return response()->json(['message' => 'Internal Server Error '.$e->getMessage(), 'status' => 4], 500);
            }
        } else {
            try {
                Certificate::where('id', $id)->update(['domain_status' => 5]);
                if ($cert->cf_id) {
                    $cf = $this->disableCF($cert->cf_id);
                    if ($cf != 'success' && $cf != 'not found') {
                        throw new \Exception('Unable to disable the DNS settings: ' . $cf);
                    }
                } else {
                    throw new \Exception("Unable to find the DNS settings.");
                }

                $empty = $s3->emptyBucket($cert->domain_name);
                if ($empty != 'success' && $empty != 'not found') {
                    throw new \Exception('Unable to delete the storage: ' . $empty);
                }
                if ($empty == 'success') {
                    $s3->deleteBucket($cert->domain_name);
                }

                $cf = $this->deleteCF($cert->cf_id);
                if ($cf != 'success' && $cf != 'not found') {
                    throw new \Exception('Unable to delete the DNS settings: ' . $cf);
                }

                $cert = $this->deleteCert($cert->certificate_id, $id);
                if ($cert == 'success' || $cert == 'Completed') {
                    Certificate::where('id', $id)->delete();
                } else {
                    throw new \Exception('Error while deleting certificate: ' . $cert);
                }
            } catch (\Exception $e) {
                return response()->json(['message' => 'Internal Server Error '.$e->getMessage(), 'status' => 5], 500);
            }
        }
    }
    public function deleteStack($stackName)
    {
        $client = new CloudFormationClient([
            'region' => config('services.aws2.region'),
            'version' => 'latest',
            'credentials' => [
                'key'    => config('services.aws2.key'),
                'secret' => config('services.aws2.secret'),
            ],
        ]);

        try {
            $result = $client->deleteStack([
                'StackName' => $stackName,
            ]);
            return "Stack deletion initiated successfully for stack";

        } catch (AwsException $e) {
            return "Error deleting stack: " . $e->getMessage();
        }
    }
    public function deletionStatus(Request $request)
    {
        $cert = Certificate::find($request->id);
        if($request->status == 4 && $cert->stack_id !='') {
            $stackName = $cert->stack_id;
            try
            {
                $client = new CloudFormationClient([
                    'region' => config('services.aws2.region'),
                    'version' => 'latest',
                    'credentials' => [
                        'key'    => config('services.aws2.key'),
                        'secret' => config('services.aws2.secret'),
                    ],
                ]);
                $response = $client->describeStacks([
                    'StackName' => $stackName,
                ]);
                $stackStatus = $response['Stacks'][0]['StackStatus'];

                if ($stackStatus == 'DELETE_COMPLETE') {
                    $st = $this->deleteCert($cert->certificate_id, $cert->id);
                    return $st;
                }
                return "Stack {$stackName} is still in status: {$stackStatus}";
            }
            catch (AwsException $e) {
                return "Error checking stack status: " . $e->getMessage();
            }
        }
        else if($request->status == 5) {
            return "Your request still in processing";
        }
        else {
            return "Something went wrong";
        }
    }
    public function deleteSSL(Request $request) {
       $cert = Certificate::find($request->id);
       $res = $this->deleteCert($cert->certificate_id, $request->id);
       if($res == 'success')
            Certificate::where('id', $request->id)->delete();
       return $res;
    }
    public function deleteCert($certificateId, $id)
    {
        try {
            $result = $this->acmClient->deleteCertificate([
                'CertificateArn' => $certificateId,
            ]);

            return "success";
        }
        catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                Certificate::where('id', $id)->delete();
                return "Completed";
            }
            return "Error deleting certificate: " . $e->getMessage();
        }
    }
    public function disableCF($distributionId)
    {
        try {
            $distribution = $this->cloudfront->getDistributionConfig([
                'Id' => $distributionId,
            ]);
            $etag = $distribution['ETag'];

            $isEnabled = $distribution['DistributionConfig']['Enabled'];
            if (!$isEnabled) return "success";

            $alias = isset($distribution['DistributionConfig']['Aliases']['Items']) ? $distribution['DistributionConfig']['Aliases']['Items'] : [];
            $updatedConfig = [
                'Id' => $distributionId,
                'IfMatch' => $etag,
                'DistributionConfig' => [
                    'Enabled' => false,
                    'CallerReference' => $distribution['DistributionConfig']['CallerReference'],
                    'Origins' => $distribution['DistributionConfig']['Origins'],
                    'DefaultCacheBehavior' => $distribution['DistributionConfig']['DefaultCacheBehavior'],
                    'Comment' => $distribution['DistributionConfig']['Comment'],
                    'ViewerCertificate' => $distribution['DistributionConfig']['ViewerCertificate'],
                    'PriceClass' => 'PriceClass_100',
                    'Aliases' => [
                        'Quantity' => $distribution['DistributionConfig']['Aliases']['Quantity'],
                        'Items' => $alias
                    ],
                    "Logging" => $distribution['DistributionConfig']['Logging'],
                    'DefaultRootObject' => $distribution['DistributionConfig']['DefaultRootObject'],
                    'WebACLId' => $distribution['DistributionConfig']['WebACLId'],
                    'HttpVersion' => $distribution['DistributionConfig']['HttpVersion'],
                    'CacheBehaviors' => $distribution['DistributionConfig']['CacheBehaviors'],
                    'CustomErrorResponses' => $distribution['DistributionConfig']['CustomErrorResponses'],
                    'Restrictions' => $distribution['DistributionConfig']['Restrictions'],
                ]
            ];
            $this->cloudfront->updateDistribution($updatedConfig);
            return "success";
        }
        catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return "not found";
            }
            if (strpos($e->getMessage(), 'NoSuchDistribution') !== false) {
                return "not found";
            }
            return "Error deleting CloudFront distribution: " . $e->getMessage();
        }
    }
    public function deleteCF($distributionId) {
        try {
            $distribution = $this->cloudfront->getDistributionConfig([
                'Id' => $distributionId,
            ]);
            $etag = $distribution['ETag'];
            $this->cloudfront->deleteDistribution([
                'Id' => $distributionId,
                'IfMatch' => $etag,
            ]);
        }
        catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return "not found";
            }
            if (strpos($e->getMessage(), 'The distribution you are trying to delete has not been disabled') !== false) {
                return 'The distribution is in processing to disabled state, please wait...';
            }
            if (strpos($e->getMessage(), 'NoSuchDistribution') !== false) {
                return "not found";
            }
            return "Error deleting certificate: " . $e->getMessage();
        }
    }
    public function getDomainList(Request $request) {
        $list = Certificate::where('user_id', $request->user_id)->select(['id', 'domain_name', 'cf_id'])->get()->toArray();
        if(is_array($list) && count($list) > 0) return response()->json($list);
        else return response()->json(['message'=>'domain not found']);
    }

    public function fixDomainID() {
        $results = Certificate::join('landing_pages', function ($join) {
            $join->on('certificates.domain_name', '=', DB::raw("CONCAT(landing_pages.bucket, '.', landing_pages.domain)"));
        })
        ->select('certificates.id as certificate_id', 'landing_pages.id as landingpage_id')
        ->get();

        foreach ($results as $result) {
            LandingPage::where('id', $result->landingpage_id)
                ->update(['domain_id' => $result->certificate_id]);
        }
    }
}






