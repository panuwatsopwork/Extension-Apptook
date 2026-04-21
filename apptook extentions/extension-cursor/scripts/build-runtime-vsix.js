const fs = require("fs");
const path = require("path");
const os = require("os");
const { execFileSync } = require("child_process");

const rootDir = path.resolve(__dirname, "..");
const packageJsonPath = path.join(rootDir, "package.json");
const packageJson = JSON.parse(fs.readFileSync(packageJsonPath, "utf8"));

const runtimeEntries = [
  "package.json",
  "bootstrap.js",
  "auth-host.js",
  "dist",
  "webview",
  "resources",
  "README.md",
  "CHANGELOG.md",
  "LICENSE.md"
];

const buildIdentity = {
  name: String(packageJson.name || "cursorpool").trim(),
  publisher: String(packageJson.publisher || "keg1255").trim(),
  displayName: String(packageJson.displayName || packageJson.name || "Cursor Extension").trim(),
  version: String(packageJson.version || "1.0.0").trim()
};

function escapeXml(value) {
  return String(value || "")
    .replace(/&/g, "&amp;")
    .replace(/"/g, "&quot;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

function ensureDir(dirPath) {
  fs.mkdirSync(dirPath, { recursive: true });
}

function copyEntry(relativePath, destinationRoot) {
  const sourcePath = path.join(rootDir, relativePath);
  if (!fs.existsSync(sourcePath)) {
    return;
  }

  const targetPath = path.join(destinationRoot, relativePath);
  const stat = fs.statSync(sourcePath);

  if (stat.isDirectory()) {
    fs.cpSync(sourcePath, targetPath, {
      recursive: true,
      force: true
    });
    return;
  }

  ensureDir(path.dirname(targetPath));
  fs.copyFileSync(sourcePath, targetPath);
}



function buildVsixManifest() {
  const extensionKind = Array.isArray(packageJson.extensionKind) ? packageJson.extensionKind.join(",") : "";
  const keywords = Array.isArray(packageJson.keywords) ? packageJson.keywords.join(",") : "";
  const categories = Array.isArray(packageJson.categories) ? packageJson.categories.join(",") : "";

  return `<?xml version="1.0" encoding="utf-8"?>
<PackageManifest Version="2.0.0" xmlns="http://schemas.microsoft.com/developer/vsx-schema/2011" xmlns:d="http://schemas.microsoft.com/developer/vsx-schema-design/2011">
  <Metadata>
    <Identity Language="en-US" Id="${escapeXml(buildIdentity.name)}" Version="${escapeXml(buildIdentity.version)}" Publisher="${escapeXml(buildIdentity.publisher)}" />
    <DisplayName>${escapeXml(buildIdentity.displayName)}</DisplayName>
    <Description xml:space="preserve">${escapeXml(packageJson.description)}</Description>
    <Tags>${escapeXml(keywords)}</Tags>
    <Categories>${escapeXml(categories)}</Categories>
    <GalleryFlags>Public</GalleryFlags>
    <Properties>
      <Property Id="Microsoft.VisualStudio.Code.Engine" Value="${escapeXml(packageJson.engines && packageJson.engines.vscode)}" />
      <Property Id="Microsoft.VisualStudio.Code.ExtensionDependencies" Value="" />
      <Property Id="Microsoft.VisualStudio.Code.ExtensionPack" Value="" />
      <Property Id="Microsoft.VisualStudio.Code.ExtensionKind" Value="${escapeXml(extensionKind)}" />
      <Property Id="Microsoft.VisualStudio.Code.LocalizedLanguages" Value="" />
      <Property Id="Microsoft.VisualStudio.Code.EnabledApiProposals" Value="" />
      <Property Id="Microsoft.VisualStudio.Code.ExecutesCode" Value="true" />
      <Property Id="Microsoft.VisualStudio.Services.GitHubFlavoredMarkdown" Value="true" />
      <Property Id="Microsoft.VisualStudio.Services.Content.Pricing" Value="Free" />
      <Property Id="Microsoft.VisualStudio.Services.Links.Source" Value="${escapeXml(packageJson.repository && packageJson.repository.url)}" />
      <Property Id="Microsoft.VisualStudio.Services.Links.Getstarted" Value="${escapeXml(packageJson.repository && packageJson.repository.url)}" />
      <Property Id="Microsoft.VisualStudio.Services.Links.Support" Value="${escapeXml(packageJson.bugs && packageJson.bugs.url)}" />
      <Property Id="Microsoft.VisualStudio.Services.Links.Learn" Value="${escapeXml(packageJson.homepage)}" />
    </Properties>
    <License>extension/LICENSE.md</License>
    <Icon>extension/${escapeXml(packageJson.icon)}</Icon>
  </Metadata>
  <Installation>
    <InstallationTarget Id="Microsoft.VisualStudio.Code" />
  </Installation>
  <Dependencies />
  <Assets>
    <Asset Type="Microsoft.VisualStudio.Code.Manifest" Path="extension/package.json" Addressable="true" />
    <Asset Type="Microsoft.VisualStudio.Services.Content.Details" Path="extension/README.md" Addressable="true" />
    <Asset Type="Microsoft.VisualStudio.Services.Content.Changelog" Path="extension/CHANGELOG.md" Addressable="true" />
    <Asset Type="Microsoft.VisualStudio.Services.Content.License" Path="extension/LICENSE.md" Addressable="true" />
    <Asset Type="Microsoft.VisualStudio.Services.Icons.Default" Path="extension/${escapeXml(packageJson.icon)}" Addressable="true" />
  </Assets>
</PackageManifest>
`;
}

function buildContentTypesXml() {
  return `<?xml version="1.0" encoding="utf-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="json" ContentType="application/json" />
  <Default Extension="js" ContentType="application/javascript" />
  <Default Extension="css" ContentType="text/css" />
  <Default Extension="html" ContentType="text/html" />
  <Default Extension="svg" ContentType="image/svg+xml" />
  <Default Extension="png" ContentType="image/png" />
  <Default Extension="md" ContentType="text/markdown" />
  <Default Extension="txt" ContentType="text/plain" />
  <Default Extension="map" ContentType="application/json" />
  <Default Extension="vsixmanifest" ContentType="text/xml" />
  <Override PartName="/extension.vsixmanifest" ContentType="text/xml" />
</Types>
`;
}

function main() {
  const requestedOutput = process.argv[2];
  const defaultOutputName = `${packageJson.name}-${packageJson.version}-runtime.vsix`;
  const outputPath = path.resolve(rootDir, requestedOutput || defaultOutputName);
  const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), "apptook-runtime-vsix-"));
  const extensionDir = path.join(tempRoot, "extension");
  const zipTempPath = path.join(tempRoot, "package.zip");

  ensureDir(extensionDir);

  runtimeEntries.forEach((entry) => copyEntry(entry, extensionDir));

  fs.writeFileSync(path.join(tempRoot, "extension.vsixmanifest"), buildVsixManifest(), "utf8");
  fs.writeFileSync(path.join(tempRoot, "[Content_Types].xml"), buildContentTypesXml(), "utf8");

  if (fs.existsSync(outputPath)) {
    fs.unlinkSync(outputPath);
  }

  execFileSync("powershell", [
    "-NoProfile",
    "-Command",
    `Compress-Archive -Path '[Content_Types].xml','extension.vsixmanifest','extension' -DestinationPath '${zipTempPath.replace(/'/g, "''")}' -Force`
  ], {
    cwd: tempRoot,
    stdio: "inherit"
  });

  fs.copyFileSync(zipTempPath, outputPath);

  fs.rmSync(tempRoot, { recursive: true, force: true });
  process.stdout.write(`${outputPath}\n`);
}

main();
