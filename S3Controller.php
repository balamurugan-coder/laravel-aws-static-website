<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ProjectsController;
use App\Http\Controllers\Controller;
use App\Providers\S3ServiceProvider;
use Illuminate\Http\Request;
use Aws\S3\S3Client;
use Aws\CloudFront\CloudFrontClient;
use Aws\Exception\AwsException;
use App\Models\LandingPage;
use App\Models\Project;
use App\Models\Certificate;
use App\Services\ProjectRepository;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Str;

class S3Controller extends Controller
{
    protected $s3Client;
    protected $storage;
    protected $distributionId;
    protected $client;
    protected  $dir_name;

    public function __construct()
    {
        $this->s3Client = new S3Client([
            'region' => config('services.aws2.region'),
            'version' => 'latest',
            'credentials' => [
                'key'    => config('services.aws2.key'),
                'secret' => config('services.aws2.secret'),
            ],
        ]);

        $this->client = new CloudFrontClient([
            'region' => config('services.aws2.region'),
            'version' => 'latest',
            'credentials' => [
                'key'    => config('services.aws2.key'),
                'secret' => config('services.aws2.secret'),
            ],
        ]);
        $this->storage = Storage::disk('projects2');

    }
    public function update(Request $request) {
        $project = Project::where('id', $request->projectid)->with('landingPages')->first();
        if($project && $project->landingPages) {
            $project_dir = $project->user_id.'/'.$project->uuid;
            $bucketName = $project->landingPages->bucket.'.'.$project->landingPages->domain;
            $local = public_path("storage/projects/$project_dir");
            $s3_dir = $project->landingPages->dir_name;
            $this->dir_name = $project->landingPages->dir_name;
            $this->distributionId = $project->landingPages->cloudfront;
            return $this->uploadDirectory($bucketName, $local, $s3_dir);
        }
    }
    public function save(Request $request) {
        $project = Project::where('id', $request->projectid)->first();
        $cert = Certificate::find($request->domain);
        if($project) {
            $project_dir = $project->user_id.'/'.$project->uuid;
            //$this->distributionId = $request->cloudfront;
            $this->distributionId = $cert->cf_id;
            $this->dir_name = $request->dir_name;
            if($request->action == 'bucket')
                return $this->createBucket($request);
            if($request->action == 'clean') {
                return $this->cleanDirectory($project);
            }
            if($request->action == 'upload') {
              //$bucketName = $request->bucket_name.'.'.$request->domain;
              $bucketName = $cert->domain_name;
              $local = public_path("storage/projects/$project_dir");
              $s3_dir = $request->dir_name;
              return $this->uploadDirectory($bucketName, $local, $s3_dir);
            }
        }
    }

    public function cleanDirectory($project) {
        $projectPath = $project->user_id.'/'.$project->uuid;
        $name ='index';
        $name = Str::contains($name, '.html') ? $name : "$name.html";
        $pagePath = "$projectPath/$name";
        $html = $this->storage->get($pagePath);
        //replace base tag
        $html = preg_replace('/<base.href=".+?">/', '', $html);

        //convert page into dom
        $crawler = new Crawler($html);
        $styleLinks = $crawler->filter('link')->extract(['href']);
        $html = $this->prefixAssetUrls($html, $styleLinks, $projectPath);
        $scriptSrc = $crawler->filter('script')->extract(['src']);
        $html = $this->prefixAssetUrls($html, $scriptSrc, $projectPath);
        //collet only project assets
        $imgSrc = $crawler->filter('img')->extract(['src']);
        $html = $this->prefixAssetUrls($html, $imgSrc, $projectPath);
        $stylesWithUrl = collect(
            $crawler->filter('*[style*="url("]')->extract(['style']),
        );
        $styleUrls = $stylesWithUrl
        ->flatMap(fn($cssStyles) => explode(';', $cssStyles))
        ->flatMap(fn($cssPropAndValue) => explode(':', $cssPropAndValue, 2))
        ->map(fn($cssValue) => trim($cssValue))
        ->filter(fn($cssValue) => Str::startsWith($cssValue, 'url'))
        ->map(function ($valueWithUrl) {
            $valueWithUrl = explode(' ', $valueWithUrl)[0];
            $valueWithUrl = preg_replace('/^url\(/', '', $valueWithUrl);
            return trim($valueWithUrl, ')"');
        });
        $html = $this->prefixAssetUrls($html, $styleUrls->toArray(), $projectPath);
        //remove content editable
        $html = preg_replace('/\s*contenteditable=["\'][^"\']*["\']/', '', $html);
        //remove data-ar-id
        $html = preg_replace('/\s*data-ar-id=["\'][^"\']*["\']/', '', $html);
        $this->storage->put(
            "$projectPath/index.html",
            $html ?? '',
        );
        return response()->json([
            'success' => true,
            'message' => 'Project files are uploading.. please wait'
        ]);
    }

    private function prefixAssetUrls(string $html, array $urls, string $baseUri): string
    {
        $cssMatch =['custom_elements.css', 'code_editor_styles.css'];
        $jsMatch = ['code_editor_scripts.js'];
        foreach (array_unique($urls) as $url) {
            if(strpos($url, 'project-assets') !== false) {
                $baseName = basename(parse_url($url, PHP_URL_PATH));
                $baseName = trim($baseName, "'\"");
                $dir = getcwd().'/storage/projects/'.$baseUri.'/images';
                $src = getcwd()."/storage/project-assets/$baseName";
                if(!is_dir($dir)) mkdir($dir, 0755);
                if(file_exists($src))  {
                    copy($src, $dir.'/'.$baseName);
                    $html = str_replace($url, "images/$baseName", $html);
                }
            }
            //replace absolute with relative
           else if ($url && !Str::startsWith($url, ['//', 'http', 'https'])) {
                $baseName = basename(parse_url($url, PHP_URL_PATH));
                if(in_array($baseName, $cssMatch))
                    $html = str_replace($url, "css/$baseName", $html);
                else if(in_array($baseName, $jsMatch))
                    $html = str_replace($url, "js/$baseName", $html);
            }
        }
        return $html;
    }


    public function invalidateAll()
    {
        $path = "/$this->dir_name/*";
        try {
            $result = $this->client->createInvalidation([
                'DistributionId' => $this->distributionId,
                'InvalidationBatch' => [
                    'Paths' => [
                        'Quantity' => 1,
                        'Items'    => [$path], // Invalidate all files
                    ],
                    'CallerReference' => uniqid(), // Unique string to ensure the request is unique
                ],
            ]);

            return $result;
        } catch (AwsException $e) {
            // Log the error or handle it as needed
            return $e->getMessage();
        }
    }

    public function createBucket(Request $request)
    {

        $cert = Certificate::find($request->domain);
        $bucketName = $cert->domain_name;
        $subdomain = $this->getSubdomainAndDomain($bucketName);
        $bucket_name = $subdomain['subdomain'];
        $domain = $subdomain['domain'];

        $exists = LandingPage::where('domain', $domain)
        ->where('bucket', $bucket_name)
        ->where('dir_name', $request->dir_name)
        ->where('project_id', '!=', $request->projectid)
        ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'The directory combination already exists.',
                'status' => 409
            ], 409);
        }

        $updated =LandingPage::where('project_id', $request->projectid)
        ->where('user_id', $request->userid)
        ->update([
            'bucket' => $bucket_name,
            'domain' =>  $domain,
            'edit' => $request->edit,
            'dir_name' => $request->dir_name,
            'cloudfront' => $cert->cf_id,
            'domain_id'=>$cert->id,
            'published' => 1,
        ]);
        if ($updated === 0) {
            LandingPage::insert([
            'project_id' => $request->projectid,
            'user_id' => $request->userid,
            'bucket' => $bucket_name,
            'domain' => $domain,
            'edit' => $request->edit,
            'dir_name' => $request->dir_name,
            'cloudfront' => $cert->cf_id,
            'published' => 1,
            'domain_id'=>$cert->id
            ]);
        }
        return response()->json([
            'success' => true,
            'message' => 'please wait files are being uploaded'
        ]);

        $bucketName = $request->bucket_name.'.'.$request->domain;
        try {
            $result = $this->s3Client->headBucket([
                'Bucket' => $bucketName,
            ]);
        }
        catch (AwsException $e) {
            if ($e->getStatusCode() === 404) {
                 // Ensure bucket name validation allows periods
                if (!preg_match('/^[a-z0-9.-]{3,63}$/', $bucketName) || preg_match('/^[0-9]{1,3}(\.[0-9]{1,3}){3}$/', $bucketName)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid bucket name. Ensure it follows the S3 naming rules.',
                    ], 400);
                }
                try {
                    $this->s3Client->createBucket([
                        'Bucket' => $bucketName
                    ]);
                }
                catch (AwsException $e) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage(),
                    ]);
                }
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        try {
            //Change Object Ownership to allow ACLs
            // $this->s3Client->putBucketOwnershipControls([
            //     'Bucket' => $bucketName,
            //     'OwnershipControls' => [
            //         'Rules' => [
            //             [
            //                 'ObjectOwnership' => 'BucketOwnerPreferred', // or 'ObjectWriter'
            //             ],
            //         ],
            //     ],
            // ]);

            //Adjust the bucket policy
            $bucketPolicy = [
                'Version' => '2012-10-17',
                'Statement' => [
                    [
                        'Effect' => 'Allow',
                        'Principal' => '*',
                        'Action' => [
                            's3:PutObject',
                            's3:GetObject'
                        ],
                        'Resource' => 'arn:aws:s3:::' . $bucketName . '/*',
                    ],
                ],
            ];

            // Wait until the bucket is created
            $this->s3Client->waitUntil('BucketExists', [
                'Bucket' => $bucketName,
            ]);

            // Create a folder inside the bucket
            $this->s3Client->putObject([
                'Bucket' => $bucketName,
                'Key'    => "$request->dir_name/",
                'Body'   => '',
            ]);

            // $this->s3Client->putPublicAccessBlock([
            //     'Bucket' => $bucketName,
            //     'PublicAccessBlockConfiguration' => [
            //         'BlockPublicAcls' => false,
            //         'IgnorePublicAcls' => false,
            //         'BlockPublicPolicy' => false,
            //         'RestrictPublicBuckets' => false,
            //     ],
            // ]);

            // Configure the bucket for static website hosting
            // $this->s3Client->putBucketWebsite([
            //     'Bucket' => $bucketName,
            //     'WebsiteConfiguration' => [
            //         'IndexDocument' => ['Suffix' => 'index.html'],
            //         'ErrorDocument' => ['Key' => 'error.html'],
            //     ],
            // ]);

            // $this->s3Client->putBucketPolicy([
            //     'Bucket' => $bucketName,
            //     'Policy' => json_encode($bucketPolicy),
            // ]);

            // Construct the endpoint URL for static website hosting
            $region = env('AWS_DEFAULT_REGION');
            $endpoint = "http://{$bucketName}.s3-website.{$region}.amazonaws.com";

            $updated =LandingPage::where('project_id', $request->projectid)
            ->where('user_id', $request->userid)
            ->update([
                'bucket' => $request->bucket_name,
                'domain' => $request->domain,
                'edit' => $request->edit,
                'dir_name' => $request->dir_name,
                'cloudfront' => $request->cloudfront,
                'published' => 1,
            ]);
            if ($updated === 0) {
                LandingPage::insert([
                'project_id' => $request->projectid,
                'user_id' => $request->userid,
                'bucket' => $request->bucket_name,
                'domain' => $request->domain,
                'edit' => $request->edit,
                'dir_name' => $request->dir_name,
                'cloudfront' => $request->cloudfront,
                'published' => 1,
                ]);
            }
            return response()->json([
                'success' => true,
                'message' => 'please wait files are being uploaded'
            ]);
        } catch (AwsException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function uploadDirectory($bucketName, $directoryPath, $s3_dir) {
        // Ensure the directory path has a trailing slash
        $directoryPath = rtrim($directoryPath, '/') . '/';

        // Get the relative path inside the directory
        $relativePath = substr($directoryPath, strrpos($directoryPath, '/') + 1);

        // Create Recursive Directory Iterator
        $directoryIterator = new \RecursiveDirectoryIterator($directoryPath, \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directoryIterator, \RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $filePath = $file->getPathname();

                // Construct the key by removing the directoryPath from filePath
                $key = $s3_dir.'/'.str_replace('\\', '/', substr($filePath, strlen($directoryPath)));

                try {
                    $result = $this->s3Client->putObject([
                        'Bucket' => $bucketName,
                        'Key'    => $key,
                        'SourceFile' => $filePath
                        //'ACL'    => 'public-read', // Adjust ACL as needed
                    ]);
                    //echo "Uploaded {$filePath} as {$key}\n";
                } catch (AwsException $e) {
                    echo "Error uploading {$filePath}: " . $e->getMessage() . "\n";
                }
            }
        }
        $this->invalidateAll();
        return response()->json([
            'success' => true,
            'message' => 'Website published successfully'
        ]);
    }

    public function emptyBucket($bucketName)
    {
        try {
            $objects = $this->s3Client->listObjects([
                'Bucket' => $bucketName
            ]);
            if (isset($objects['Contents']) && !empty($objects['Contents'])) {
                $keys = array_map(function ($object) {
                    return ['Key' => $object['Key']];
                }, $objects['Contents']);

                $result = $this->s3Client->deleteObjects([
                    'Bucket' => $bucketName,
                    'Delete' => [
                        'Objects' => $keys,
                    ]
                ]);
                return 'success';
            }
            else {
                return 'success';
            }
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return "not found";
            }
            if (strpos($e->getMessage(), 'NoSuchBucket') !== false) {
                return "not found";
            }
            return $e->getMessage();
        }
    }

    public function deleteBucketDir($bucketName, $dirname)
    {
        try {
            $objects = $this->s3Client->listObjects([
                'Bucket' => $bucketName,
                'Prefix' => rtrim($dirname, '/') . '/' // Ensure the directory prefix ends with '/'
            ]);
            if (isset($objects['Contents']) && !empty($objects['Contents'])) {
                $keys = array_map(function ($object) {
                    return ['Key' => $object['Key']];
                }, $objects['Contents']);

                $result = $this->s3Client->deleteObjects([
                    'Bucket' => $bucketName,
                    'Delete' => [
                        'Objects' => $keys,
                    ]
                ]);
                return 'success';
            } else {
                return 'success';
            }
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException' ||
                strpos($e->getMessage(), 'NoSuchBucket') !== false) {
                return "not found";
            }
            return $e->getMessage();
        }
    }

    public function deleteBucket($bucketName)
    {
        try {
            $this->s3Client->deleteBucket([
                'Bucket' => $bucketName
            ]);
            return "success";
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return "not found";
            }
            if (strpos($e->getMessage(), 'NoSuchBucket') !== false) {
                return "not found";
            }
            return "Error deleting S3 bucket: " . $e->getMessage();
        }
    }
    public function getSubdomainAndDomain($host) {
        // Regular expression to match subdomain and domain
        $pattern = '/^([a-z0-9-]+)\.([a-z0-9-]+\.[a-z]{2,})$/i';

        if (preg_match($pattern, $host, $matches)) {
            // If matched, subdomain is the first group, and domain is the second group
            return [
                'subdomain' => $matches[1],  // Subdomain
                'domain' => $matches[2]      // Domain
            ];
        } else {
            // No subdomain, just the domain
            return [
                'subdomain' => null,  // No subdomain
                'domain' => $host     // Full domain
            ];
        }
    }
    public function deleteLP(Request $request, Project $project) {
        $lp = LandingPage::find($request->id);
        return app(ProjectsController::class)->destroy($lp->project_id);
    }

}
