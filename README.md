# PerturbCorpus

PerturbCorpus 是一个面向 AI 的基因扰动数据库，整合了来自公开数据库的 bulk 和 single-cell RNA-seq 扰动数据，构建统一、分析就绪的扰动资源平台。该项目旨在为基因扰动效应研究、跨数据集比较以及虚拟细胞/虚拟扰动建模提供高质量、标准化、可用于模型训练的数据支撑。

本项目采用 PHP、JavaScript、Bootstrap 和 SQLite 构建网站，并提供数据浏览、元数据展示、扰动相似性分析、遗传互作分类以及下载服务等功能。

## 项目简介

该项目致力于构建目前较为完整的 bulk 与 single-cell 基因扰动数据库，统一处理不同来源、不同实验设计和不同注释体系的数据，从而实现跨数据集比较与模型训练所需的标准化输入。该平台支持：

- bulk RNA-seq 扰动数据
- single-cell RNA-seq 扰动数据
- 元数据标准化与跨数据集整合
- 扰动相似性比较分析
- 遗传互作分析
- 面向 AI 模型训练和虚拟细胞扰动研究的可用数据资源

整个系统既服务于生物学发现，也为机器学习与类虚拟细胞研究提供统一、可复用的数据基础。

## 主要功能

### 1. 统一的扰动数据库
- 整合来自 NCBI GEO 等公开数据源的 bulk 和 single-cell 扰动数据
- 对物种、组织、细胞类型、细胞系、assay 类型、扰动基因等元数据进行标准化
- 统一处理基因命名和扰动信息，提升跨数据集可比性

### 2. 数据集浏览与详细页面
- 按物种、组织、细胞类型、assay 类型、扰动基因进行数据浏览
- 支持按数据集 ID、GSM accession、GSE accession、组织、biosample description、靶基因等关键词搜索
- 提供数据集统计信息、扰动标注、质量控制、DEG 分析等详细内容

### 3. 扰动分析工具
- Bulk Correlation Explorer
- Single-cell Correlation Explorer
- Bulk Genetic Interaction Classifier
- Single-cell Genetic Interaction Classifier

这些工具可用于比较扰动特征、分析转录组相似性，并探索多基因互作模式。

### 4. 下载中心
- 支持下载处理后的 bulk 和 single-cell 数据集
- 提供数据集文件及其对应元数据
- 便于下游重现性分析和模型开发

### 5. 数据库和前端技术栈
- PHP 负责后端渲染与数据查询
- JavaScript 实现交互式前端逻辑
- Bootstrap 5 构建响应式界面
- SQLite 作为核心数据库后端，承载元数据和分析结果

## 数据收集与处理流程

该项目采用统一的标准化流程进行数据收集和整理，重点覆盖 NCBI 及相关公开数据库中的元数据和扰动相关实验数据。

### 工作流程
1. 收集 NCBI / GEO 等公共数据源中的元数据
2. 筛选和过滤候选扰动数据集
3. 下载原始测序数据及其相关元数据
4. 构建统一的元数据字段并完成注释标准化
5. 对 bulk 或 single-cell 数据执行统一处理流程
6. 进行质量控制与扰动状态分析
7. 将结果写入 SQLite 数据库供网页查询和分析使用
8. 接入分析工具和下载服务

### 处理说明
- Bulk RNA-seq：采用统一的表达定量和扰动效应汇总流程进行处理
- Single-cell RNA-seq：在细胞水平进行 QC，并在适用场景下进行 sgRNA 分配、扰动状态分类等分析
- 不同数据集的元数据统一映射到相同字段体系，以提高跨数据集比较和 AI 应用的可用性

## 技术栈

- PHP
- JavaScript
- Bootstrap 5
- SQLite
- HTML / CSS
- NCBI GEO 及公开测序数据资源

## 仓库结构

```text
.
├── index.php                  # 首页
├── browse.php                 # 数据浏览页面
├── browse_detail.php          # 数据集详细页
├── statistics.php             # 项目统计与汇总页面
├── download.php               # 下载页面
├── faq.php                    # 常见问题页面
├── bulk_correlation_tool.php   # bulk 扰动相似性分析工具
├── sc_correlation_tool.php     # single-cell 扰动相似性分析工具
├── bulk_gi_tool.php           # bulk 遗传互作分类工具
├── sc_gi_tool.php             # single-cell 遗传互作分类工具
├── background.php             # 公共页面背景与主题逻辑
├── config.php                 # 数据库和站点配置
├── static/                    # CSS / JS / 静态资源
├── sqlite3/                   # SQLite 数据库文件
├── .gitignore                 # git 忽略规则
├── README.md                  # 项目说明文档
└── ...
```

> 说明：原始数据文件或大规模处理结果可能存放在外部数据仓库或部署环境中，本仓库主要包含网站应用与数据库接入逻辑。

## 部署状态

该项目已完成部署并已上线运行，可通过浏览器访问数据检索、分析工具和下载服务等功能。

## 本地运行

### 环境要求
- PHP 7.4+，推荐 PHP 8.x
- 开启 SQLite 支持
- Apache / Nginx 或本地 PHP 内置服务器

### 本地启动

```bash
git clone https://github.com/xhuan666/PerturbCorpus
cd PerturbCorpus
php -S 127.0.0.1:8000
```

然后在浏览器打开：

```text
http://127.0.0.1:8000/
```

### 配置说明
项目配置位于 `config.php`，包括 SQLite 数据库路径、下载地址等信息。例如：

- `DB_META_FILE`：主元数据数据库
- `DB_GENEEXP_FILE`：bulk 表达数据库
- `DB_BULK_DEG_FILE`：bulk DEG 数据库
- `DB_SC_PERTURB_FREQ_FILE`：single-cell 扰动频率数据库
- `DOWNLOAD_BASE_URL`：下载地址配置

## 典型应用场景

- 按物种、组织和基因浏览扰动数据集
- 比较 bulk / single-cell 扰动之间的效应相似性
- 针对特定扰动进行假设生成和功能分析
- 分析基因-基因互作模式
- 为 AI / ML 工作流准备标准化扰动数据
- 构建虚拟细胞扰动训练数据集

## 研究价值

PerturbCorpus 旨在支持：

- 系统性建模基因扰动效应
- 跨数据集扰动比较分析
- 识别扰动转录组中的生物学模式
- 为机器学习与虚拟细胞研究生成标准化数据资源
=======
# PerturbCorpus

PerturbCorpus 是一个面向 AI 的基因扰动数据库，整合了来自公开数据库的 bulk 和 single-cell RNA-seq 扰动数据，构建统一、分析就绪的扰动资源平台。该项目旨在为基因扰动效应研究、跨数据集比较以及虚拟细胞/虚拟扰动建模提供高质量、标准化、可用于模型训练的数据支撑。

本项目采用 PHP、JavaScript、Bootstrap 和 SQLite 构建网站，并提供数据浏览、元数据展示、扰动相似性分析、遗传互作分类以及下载服务等功能。

## 项目简介

该项目致力于构建目前较为完整的 bulk 与 single-cell 基因扰动数据库，统一处理不同来源、不同实验设计和不同注释体系的数据，从而实现跨数据集比较与模型训练所需的标准化输入。该平台支持：

- bulk RNA-seq 扰动数据
- single-cell RNA-seq 扰动数据
- 元数据标准化与跨数据集整合
- 扰动相似性比较分析
- 遗传互作分析
- 面向 AI 模型训练和虚拟细胞扰动研究的可用数据资源

整个系统既服务于生物学发现，也为机器学习与类虚拟细胞研究提供统一、可复用的数据基础。

## 主要功能

### 1. 统一的扰动数据库
- 整合来自 NCBI GEO 等公开数据源的 bulk 和 single-cell 扰动数据
- 对物种、组织、细胞类型、细胞系、assay 类型、扰动基因等元数据进行标准化
- 统一处理基因命名和扰动信息，提升跨数据集可比性

### 2. 数据集浏览与详细页面
- 按物种、组织、细胞类型、assay 类型、扰动基因进行数据浏览
- 支持按数据集 ID、GSM accession、GSE accession、组织、biosample description、靶基因等关键词搜索
- 提供数据集统计信息、扰动标注、质量控制、DEG 分析等详细内容

### 3. 扰动分析工具
- Bulk Correlation Explorer
- Single-cell Correlation Explorer
- Bulk Genetic Interaction Classifier
- Single-cell Genetic Interaction Classifier

这些工具可用于比较扰动特征、分析转录组相似性，并探索多基因互作模式。

### 4. 下载中心
- 支持下载处理后的 bulk 和 single-cell 数据集
- 提供数据集文件及其对应元数据
- 便于下游重现性分析和模型开发

### 5. 数据库和前端技术栈
- PHP 负责后端渲染与数据查询
- JavaScript 实现交互式前端逻辑
- Bootstrap 5 构建响应式界面
- SQLite 作为核心数据库后端，承载元数据和分析结果

## 数据收集与处理流程

该项目采用统一的标准化流程进行数据收集和整理，重点覆盖 NCBI 及相关公开数据库中的元数据和扰动相关实验数据。

### 工作流程
1. 收集 NCBI / GEO 等公共数据源中的元数据
2. 筛选和过滤候选扰动数据集
3. 下载原始测序数据及其相关元数据
4. 构建统一的元数据字段并完成注释标准化
5. 对 bulk 或 single-cell 数据执行统一处理流程
6. 进行质量控制与扰动状态分析
7. 将结果写入 SQLite 数据库供网页查询和分析使用
8. 接入分析工具和下载服务

### 处理说明
- Bulk RNA-seq：采用统一的表达定量和扰动效应汇总流程进行处理
- Single-cell RNA-seq：在细胞水平进行 QC，并在适用场景下进行 sgRNA 分配、扰动状态分类等分析
- 不同数据集的元数据统一映射到相同字段体系，以提高跨数据集比较和 AI 应用的可用性

## 技术栈

- PHP
- JavaScript
- Bootstrap 5
- SQLite
- HTML / CSS
- NCBI GEO 及公开测序数据资源

## 仓库结构

```text
.
├── index.php                  # 首页
├── browse.php                 # 数据浏览页面
├── browse_detail.php          # 数据集详细页
├── statistics.php             # 项目统计与汇总页面
├── download.php               # 下载页面
├── faq.php                    # 常见问题页面
├── bulk_correlation_tool.php   # bulk 扰动相似性分析工具
├── sc_correlation_tool.php     # single-cell 扰动相似性分析工具
├── bulk_gi_tool.php           # bulk 遗传互作分类工具
├── sc_gi_tool.php             # single-cell 遗传互作分类工具
├── background.php             # 公共页面背景与主题逻辑
├── config.php                 # 数据库和站点配置
├── static/                    # CSS / JS / 静态资源
├── sqlite3/                   # SQLite 数据库文件
├── .gitignore                 # git 忽略规则
├── README.md                  # 项目说明文档
└── ...
```

> 说明：原始数据文件或大规模处理结果可能存放在外部数据仓库或部署环境中，本仓库主要包含网站应用与数据库接入逻辑。

## 部署状态

该项目已完成部署并已上线运行，可通过浏览器访问数据检索、分析工具和下载服务等功能。

## 本地运行

### 环境要求
- PHP 7.4+，推荐 PHP 8.x
- 开启 SQLite 支持
- Apache / Nginx 或本地 PHP 内置服务器

### 本地启动

```bash
git clone https://github.com/xhuan666/PerturbCorpus
cd PerturbCorpus
php -S 127.0.0.1:8000
```

然后在浏览器打开：

```text
http://127.0.0.1:8000/
```

### 配置说明
项目配置位于 `config.php`，包括 SQLite 数据库路径、下载地址等信息。例如：

- `DB_META_FILE`：主元数据数据库
- `DB_GENEEXP_FILE`：bulk 表达数据库
- `DB_BULK_DEG_FILE`：bulk DEG 数据库
- `DB_SC_PERTURB_FREQ_FILE`：single-cell 扰动频率数据库
- `DOWNLOAD_BASE_URL`：下载地址配置

## 典型应用场景

- 按物种、组织和基因浏览扰动数据集
- 比较 bulk / single-cell 扰动之间的效应相似性
- 针对特定扰动进行假设生成和功能分析
- 分析基因-基因互作模式
- 为 AI / ML 工作流准备标准化扰动数据
- 构建虚拟细胞扰动训练数据集

## 研究价值

PerturbCorpus 旨在支持：

- 系统性建模基因扰动效应
- 跨数据集扰动比较分析
- 识别扰动转录组中的生物学模式
- 为机器学习与虚拟细胞研究生成标准化数据资源

