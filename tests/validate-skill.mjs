import assert from 'node:assert/strict'
import { readFile, readdir } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const testsDirectory = path.dirname(fileURLToPath(import.meta.url))
const repositoryRoot = path.resolve(testsDirectory, '..')
const canonicalRelativePath = 'skills/access402/SKILL.md'
const canonicalPath = path.join(repositoryRoot, canonicalRelativePath)
const metadataPath = path.join(repositoryRoot, 'skills/access402/agents/openai.yaml')

const skill = await readFile(canonicalPath, 'utf8')
const metadata = await readFile(metadataPath, 'utf8')
const packageJson = JSON.parse(await readFile(path.join(repositoryRoot, 'package.json'), 'utf8'))

const frontmatterMatch = skill.match(/^---\r?\n([\s\S]*?)\r?\n---(?:\r?\n|$)/)
assert(frontmatterMatch, 'SKILL.md must start with YAML frontmatter')

const frontmatter = frontmatterMatch[1]
assert.match(frontmatter, /^name:\s*access402\s*$/m, 'frontmatter must contain name: access402')

const descriptionMatch = frontmatter.match(/^description:\s*(.+?)\s*$/m)
assert(descriptionMatch, 'frontmatter must contain a description')
assert(descriptionMatch[1].replace(/^['"]|['"]$/g, '').trim(), 'description must not be empty')

const frontmatterKeys = [...frontmatter.matchAll(/^([a-zA-Z0-9_-]+):/gm)].map((match) => match[1])
assert.deepEqual(frontmatterKeys.sort(), ['description', 'name'], 'frontmatter may contain only name and description')

const lineCount = skill.split(/\r?\n/).length
assert(lineCount < 500, `SKILL.md must remain below 500 lines; found ${lineCount}`)

assert.match(metadata, /display_name:\s*["']Access402["']/, 'openai.yaml must define the display name')
assert.match(metadata, /short_description:\s*["'].+["']/, 'openai.yaml must define a short description')
assert.match(metadata, /default_prompt:\s*["'].*\$access402.*["']/, 'openai.yaml default prompt must invoke $access402')
assert.match(metadata, /brand_color:\s*["']#[0-9A-Fa-f]{6}["']/, 'openai.yaml must define the Access402 brand color')

const requiredNetworkValues = [
  'eip155:84532',
  'eip155:8453',
  '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
  '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
]

for (const value of requiredNetworkValues) {
  assert(skill.includes(value), `SKILL.md must contain ${value}`)
}

const requiredSafetyPatterns = [
  /x402 version 2 only/i,
  /Ask the user to choose Sandbox\/test or Live\/production/i,
  /credentials and installation secrets server-side/i,
  /never ask for or accept private keys, seed phrases, Coinbase credentials, dashboard JWTs, or installation API keys/i,
  /fail closed/i,
  /duplicate slashes/i,
  /encoded slashes or backslashes/i,
  /Cache-Control: private, no-store/i,
  /Never infer Live/i,
]

for (const pattern of requiredSafetyPatterns) {
  assert.match(skill, pattern, `SKILL.md is missing required security language: ${pattern}`)
}

async function collectFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true })
  const files = []

  for (const entry of entries) {
    if (entry.name === '.git' || entry.name === 'node_modules') continue
    const absolutePath = path.join(directory, entry.name)
    if (entry.isDirectory()) files.push(...await collectFiles(absolutePath))
    else if (entry.isFile()) files.push(absolutePath)
  }

  return files
}

const repositoryFiles = await collectFiles(repositoryRoot)
const skillFiles = repositoryFiles
  .filter((file) => path.basename(file) === 'SKILL.md')
  .map((file) => path.relative(repositoryRoot, file).split(path.sep).join('/'))

assert.deepEqual(skillFiles, [canonicalRelativePath], `expected exactly one canonical SKILL.md, found: ${skillFiles.join(', ')}`)
assert.equal(packageJson.private, true, 'package.json must set "private": true')

const secretPatterns = [
  /-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/,
  /\bAKIA[0-9A-Z]{16}\b/,
  /\bgh[pousr]_[A-Za-z0-9]{30,}\b/,
  /\bsk-(?:live-|test-)?[A-Za-z0-9]{20,}\b/,
  /\bsk_live_[A-Za-z0-9]{16,}\b/,
  /\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/,
  /\b0x[a-fA-F0-9]{64}\b/,
  /(?:COINBASE|CDP|ACCESS402|INSTALLATION)[A-Z0-9_]*(?:SECRET|TOKEN|KEY|PASSWORD)\s*=\s*[^\s<][^\r\n]*/i,
]

for (const file of repositoryFiles) {
  const relativePath = path.relative(repositoryRoot, file)
  const content = await readFile(file, 'utf8')
  for (const pattern of secretPatterns) {
    assert(!pattern.test(content), `possible secret detected in ${relativePath}: ${pattern}`)
  }
}

console.log(`Access402 skill validation passed (${lineCount} lines, ${repositoryFiles.length} repository files).`)
