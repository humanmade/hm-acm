<table width="100%">
	<tr>
		<td align="left" width="70">
			<strong>HM ACM</strong><br />
			WordPress plugin for user-land AWS ACM and CloudFront HTTPS
		</td>
		<td align="right" width="20%"></td>
	</tr>
	<tr>
		<td>
			A <strong><a href="https://hmn.md/">Human Made</a></strong> project. Maintained by @joehoyle.
		</td>
		<td align="center">
			<img src="https://hmn.md/content/themes/hmnmd/assets/images/hm-logo.svg" width="100" />
		</td>
	</tr>
</table>

## When to use HM ACM

If you have a WordPress multisite that allows users to add their own domain names, and you want to support HTTPS on all custom domains.

Because CloudFront only supports a single HTTPS certificate, it's inpractical (and mostly impossible) to update to a new SSL certification that includes a new custom domain every time a user on the network configures their site's domain.

## How HM ACM works

The basic idea is to generate a new ACM certificate for every domain configured on the multisite, and then use that SSL certificate on a new CloudFront distribituion, specific to each site with a custom domain.

This plugin handles the API calls and steps to AWS to generate the ACM SSL certificate and create the CloudFront Distribution. The plugin has admin UI to step the user through this process.

The CloudFront Distribution Config is hard coded in this plugin, and reflects the CloudFront Distribution in use under Human Made's typical config. This should ideally be updated to be synchonrised with any updates made to the "base" network CloudFront Distribution Config.

## Configuration

HM ACM needs access to the AWS APIs for CloudFront and ACM. To pass the API credentials, you must define the `HM_ACM_AWS_KEY` and `HM_ACM_AWS_SECRET` constants. You also have to define `HM_ACM_UPSTREAM_DOMAIN` (upstream CloudFront domain name) to set correct origin for new domain.

Also the constants need to be defined for `HM_ACM_UPSTREAM_CLOUDFRONT_FUNCTION_ARN` which must be a CloudFront function ARN. This is used to forward the Host header to the upstream CloudFront distribution.

`HM_ACM_CLOUDFRONT_CACHE_POLICY_ID` must be defined as the ID of the CloudFront cache policy to use for the distribution.

`HM_ACM_CLOUDFRONT_ORIGIN_REQUEST_POLICY_ID` must be defined as the ID of the CloudFront origin request policy to use for the distribution.

The AWS Access Key should have the following policy:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "VisualEditor0",
            "Effect": "Allow",
            "Action": [
                "cloudfront:GetDistribution",
                "acm:DescribeCertificate",
                "acm:RequestCertificate",
                "cloudfront:CreateDistribution",
                "cloudfront:UpdateDistribution"
            ],
            "Resource": "*"
        }
    ]
}
```

## Limitations

Because this plugin doesn't provide DNS / Nameserver services (via Route 53) it is not possible to use a root domain with the CloudFront distribution. This is because AWS does not provide IP addresses for the CDN, so we don't have anything to provide users with to add an `A` record to their DNS.

The path forward here is probably to incorperate Route 53 in to this plugin, so instead of providing users with DNS records, we give them nameservers to switch to. This adds the complication of needing to add UI for general DNS management, as users will likely need to now manage things like MX records.

## User guide

To do the following you current have to activate the "HM ACM HTTP" plugin on the site.

Step 1: Request HTTPS Certificate

<img width="1216" alt="screenshot 2018-10-25 at 14 03 34" src="https://user-images.githubusercontent.com/161683/47521798-f514ab80-d861-11e8-825c-9c6ec7f0ac46.png">

Step 2: Once certificate is requested, I must verify the domain by adding DNS records:

<img width="1216" alt="screenshot 2018-10-25 at 14 04 06" src="https://user-images.githubusercontent.com/161683/47521836-0eb5f300-d862-11e8-9cc9-06a73f48f8ce.png">

After 5 minutes, I click "Refresh" in the plugin admin page, the certificate is now ISSUED, on to the next step:

Step 3: Click Create CDN Configuration

<img width="1216" alt="screenshot 2018-10-25 at 14 12 49" src="https://user-images.githubusercontent.com/161683/47521886-31480c00-d862-11e8-9c6f-4a83c2c11372.png">

Step 4: Update DNS records for the domain

<img width="1216" alt="screenshot 2018-10-25 at 14 14 14" src="https://user-images.githubusercontent.com/161683/47521938-4de44400-d862-11e8-94a9-51d4f48efa66.png">

Now the CDN is configured, I have new DNS settings for the www.exmaple.com domain. I update the www.example.com (leaving example.no unchanged, as the domain provider is already doing a redirect to www in this case).

<img width="1216" alt="screenshot 2018-10-25 at 14 30 08" src="https://user-images.githubusercontent.com/161683/47522059-9bf94780-d862-11e8-8689-bb01cf7add39.png">

Now the site is configured with a valid HTTPS certificate. In the case of this site, I had to then do a `search-replace` as there was lots of http:// urls stored in the content:

```
wp --url=https://www.example.com/ search-replace http://examplenetwork.com/uploads/ https://unitedbloggers.noexamplenetwork.com/uploads/
```

You should now see https://www.example.com/ functional with HTTPS.
